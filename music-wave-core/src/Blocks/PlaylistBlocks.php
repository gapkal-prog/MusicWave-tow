<?php
/**
 * Playlist presentation blocks.
 *
 * Both blocks render complete, usable HTML on the server: every control is a
 * form posted to `admin-post.php`, so creating, renaming, sharing, reordering,
 * and deleting playlists works with JavaScript disabled. Reordering uses
 * accessible move-up/move-down buttons rather than drag-and-drop only
 * (PROJECT_PLAN.md Stage 5 deliverable 4).
 *
 * Enhanced 2026-08: Your playlists is visually distinct from Music library
 * (different eyebrow, art grid, card header) and every playlist exposes a
 * one-click Play all button wired to the global queue player.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Blocks\Concerns\ActionButtonStyle;
use ManaCore\MusicWave\Core\Blocks\Concerns\EditorPreviewCatalog;
use ManaCore\MusicWave\Core\Blocks\Concerns\ReleaseResolver;
use ManaCore\MusicWave\Core\Catalog\TermHue;
use ManaCore\MusicWave\Core\Playlists\PlaylistFormHandler;
use ManaCore\MusicWave\Core\Playlists\PlaylistRepository;
use ManaCore\MusicWave\Core\Account\AuthPolicy;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PlaylistBlocks {
	/** @var PlaylistRepository */
	private $repository;

	/** @var PlaylistFormHandler */
	private $forms;

	/** @var object|null */
	private $render_context;

	public function __construct( PlaylistRepository $repository, PlaylistFormHandler $forms ) {
		$this->repository = $repository;
		$this->forms      = $forms;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		BlockSupport::register_dynamic(
			'music-wave/playlists',
			function ( $attributes ): string {
				return $this->render_manager( is_array( $attributes ) ? $attributes : array() );
			},
			array(
				'api_version' => 3,
				'supports'    => BlockSupport::appearance_tools(),
			)
		);
		BlockSupport::register_dynamic(
			'music-wave/add-to-playlist',
			function ( $attributes, $content, $block ): string {
				unset( $content );
				$previous             = $this->render_context;
				$this->render_context = is_object( $block ) ? $block : null;

				try {
					return $this->render_picker( is_array( $attributes ) ? $attributes : array() );
				} finally {
					$this->render_context = $previous;
				}
			},
			array(
				'api_version'  => 3,
				'uses_context' => array( 'postId', 'postType' ),
				'supports'     => BlockSupport::appearance_tools(),
			)
		);
		BlockSupport::register_dynamic(
			'music-wave/public-playlists',
			function ( $attributes ): string {
				return $this->render_public( is_array( $attributes ) ? $attributes : array() );
			},
			array(
				'api_version' => 3,
				'supports'    => BlockSupport::appearance_tools(),
			)
		);

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'init', array( $this, 'ensure_public_page' ), 20 );
		add_action( 'template_redirect', array( $this, 'redirect_guest_share_request' ), 5 );
	}

	/**
	 * Redirect legacy `/account/?mw-playlist={id}` links opened by guests or non-owners
	 * straight to the public `/playlists/?mw-playlist={id}` view before any login gate runs.
	 */
	public function redirect_guest_share_request(): void {
		$playlist_id = $this->requested_playlist();
		if ( $playlist_id < 1 ) {
			return;
		}
		if ( function_exists( 'is_page' ) && is_page( 'playlists' ) ) {
			return;
		}
		$viewer_id = get_current_user_id();
		$token     = $this->requested_share_token();
		$playlist  = $this->repository->find( $playlist_id );
		if ( null === $playlist ) {
			return;
		}
		if ( $viewer_id > 0 && (int) $playlist['user_id'] === $viewer_id ) {
			return;
		}
		if ( ! $this->repository->can_view( $playlist, $viewer_id, $token ) ) {
			return;
		}
		$target = $this->repository->public_url(
			$playlist_id,
			(string) $playlist['visibility'],
			PlaylistRepository::VISIBILITY_UNLISTED === (string) $playlist['visibility'] ? $token : ''
		);
		if ( '' !== $target && function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $target, 302 );
			exit;
		}
	}

	/**
	 * Enqueue progressive-enhancement script for playlists.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		// Preview player already enqueues wp-api-fetch; playlists reuses it. Enqueue only when needed
		// keeps the JS budget low, but the file is tiny (<8KB) so always enqueue for logged-in visitors
		// and for guests who can still trigger Play all on public playlists.
		$deps = array( 'wp-api-fetch' );
		if ( wp_script_is( 'music-wave-preview-player', 'registered' ) || wp_script_is( 'music-wave-preview-player', 'enqueued' ) ) {
			$deps[] = 'music-wave-preview-player';
		}

		wp_enqueue_script(
			'music-wave-playlists',
			MUSIC_WAVE_CORE_URL . 'assets/playlists.js',
			$deps,
			MUSIC_WAVE_CORE_VERSION,
			true
		);
		wp_localize_script(
			'music-wave-playlists',
			'musicWavePlaylists',
			array(
				'restUrl'    => esc_url_raw( rest_url() ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'isLoggedIn' => get_current_user_id() > 0,
				'labels'     => array(
					'playAll'            => __( 'پخش همه', 'music-wave-core' ),
					'playing'            => __( 'در حال پخش…', 'music-wave-core' ),
					'addToPlaylist'      => __( 'افزودن به فهرست پخش', 'music-wave-core' ),
					'added'              => __( 'به فهرست پخش اضافه شد.', 'music-wave-core' ),
					'created'            => __( 'فهرست پخش ایجاد شد.', 'music-wave-core' ),
					'createPlaylist'     => __( 'ایجاد فهرست پخش', 'music-wave-core' ),
					'createAndAdd'       => __( 'ایجاد و اضافه کردن', 'music-wave-core' ),
					'newPlaylist'        => __( 'نام فهرست پخش جدید', 'music-wave-core' ),
					'noPlayable'         => __( 'این فهرست پخش در حال حاضر صدای قابل پخش ندارد.', 'music-wave-core' ),
					'error'              => __( 'آن درخواست فهرست پخش معتبر نبود. دوباره امتحان کنید.', 'music-wave-core' ),
					'sessionError'       => __( 'جلسه شما تمام شده است. صفحه را تازه کنید یا دوباره وارد شوید.', 'music-wave-core' ),
					'addedBadge'         => __( 'اضافه شد', 'music-wave-core' ),
					'updated'            => __( 'فهرست پخش به‌روز شد.', 'music-wave-core' ),
					'deleted'            => __( 'فهرست پخش حذف شد', 'music-wave-core' ),
					'itemRemoved'        => __( 'از فهرست پخش حذف شد.', 'music-wave-core' ),
					'orderUpdated'       => __( 'ترتیب فهرست پخش به‌روز شد.', 'music-wave-core' ),
					'confirmDelete'      => __( 'این فهرست پخش برای همیشه حذف شود؟', 'music-wave-core' ),
					'loading'            => __( 'در حال بارگذاری…', 'music-wave-core' ),
					'emptyPlaylist'      => __( 'این فهرست پخش خالی است. در هر انتشار از «افزودن به فهرست پخش» استفاده کنید.', 'music-wave-core' ),
					'oneTrack'           => __( '1 قطعه', 'music-wave-core' ),
					'trackSingular'      => __( 'قطعه', 'music-wave-core' ),
					'trackPlural'        => __( 'قطعه', 'music-wave-core' ),
					'private'            => __( 'خصوصی', 'music-wave-core' ),
					'showTracks'         => __( 'نمایش قطعه‌ها', 'music-wave-core' ),
					'hideTracks'         => __( 'مخفی کردن قطعه‌ها', 'music-wave-core' ),
					'noResults'          => __( 'هیچ فهرست پخشی یافت نشد.', 'music-wave-core' ),
					/* translators: decorative stamp over the artwork on public playlist cards. */
					'master'             => __( 'MASTER', 'music-wave-core' ),
					'paginationLabel'    => __( 'صفحات فهرست پخش عمومی', 'music-wave-core' ),
					/* translators: 1: number shown, 2: total number of playlists. */
					'showingCount'       => __( 'نمایش %1$d از فهرست‌های پخش %2$d', 'music-wave-core' ),
					/* translators: %s: playlist title. */
					'playAllAria'        => __( 'پخش تمام قطعه‌ها در %s', 'music-wave-core' ),
					/* translators: %s: release title. */
					'playTrackAria'      => __( 'پخش %s', 'music-wave-core' ),
					'emptyOrNotPlayable' => __( 'این فهرست پخش خالی است یا قابل پخش نیست.', 'music-wave-core' ),
					/* translators: %d: release ID. */
					'releaseFallback'    => __( 'انتشار #%d', 'music-wave-core' ),
					'shareLabel'         => __( 'پیوند را به‌اشتراک بگذارید', 'music-wave-core' ),
					'shareHint'          => __( 'هر کسی که این پیوند را داشته باشد می‌تواند فهرست پخش را بدون نیاز به ورود باز و پخش کند.', 'music-wave-core' ),
					'copyLink'           => __( 'کپی پیوند', 'music-wave-core' ),
					'linkCopied'         => __( 'کپی شد ✓', 'music-wave-core' ),
					'openPublicPage'     => __( 'مشاهده صفحه ↗', 'music-wave-core' ),
					'visibilityPrivate'  => __( 'خصوصی', 'music-wave-core' ),
					'visibilityUnlisted' => __( 'هر کسی پیوند', 'music-wave-core' ),
					'visibilityPublic'   => __( 'عمومی', 'music-wave-core' ),
				),
			)
		);
	}

	/**
	 * Render the playlist manager.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_manager( array $attributes ): string {
		try {
			$user_id = get_current_user_id();
			$heading = BlockSupport::text_attribute( $attributes, 'heading', __( 'فهرست‌های پخش شما', 'music-wave-core' ) );

			if ( $user_id < 1 ) {
				$req_id = $this->requested_playlist();
				if ( $req_id > 0 ) {
					$req_token = $this->requested_share_token();
					$shared_pl = $this->repository->find( $req_id );
					$shared_vw = null !== $shared_pl ? $this->repository->view( $req_id, 0, $req_token ) : null;
					if ( null !== $shared_pl && null !== $shared_vw ) {
						return '<section ' . BlockSupport::wrapper_attributes( 'mw-playlists mw-playlists--guest mw-playlists--shared-view' ) . '>'
							. $this->shared_playlist_hero_markup( $shared_pl, $shared_vw, $req_token )
							. '</section>';
					}
				}

				return '<section ' . BlockSupport::wrapper_attributes( 'mw-playlists mw-playlists--guest' ) . '>'
				. '<div class="mw-playlists__guest"><span class="mw-playlists__eyebrow mw-playlists__eyebrow--guest"><span aria-hidden="true">♫</span> ' . esc_html__( 'فهرست‌های پخش شما', 'music-wave-core' ) . '</span>'
				. '<h2 class="mw-playlists__title">' . esc_html( $heading ) . '</h2>'
				. '<p class="mw-playlists__guest-text">' . esc_html__( 'برای ایجاد فهرست پخش از کاتالوگ خود وارد سیستم شوید.', 'music-wave-core' ) . '</p>'
				. '<a class="wp-element-button mw-playlists__guest-cta" href="' . esc_url( AuthPolicy::login_url( BlockSupport::current_url() ) ) . '">' . esc_html__( 'وارد شوید', 'music-wave-core' ) . '</a></div>'
				. '</section>';
			}

			$playlists = $this->repository->for_user( $user_id, PlaylistRepository::MAX_PLAYLISTS );
			$expanded  = $this->requested_playlist();
			$total     = count( $playlists );

			$rows = array();
			foreach ( $playlists as $playlist ) {
				$rows[] = $this->playlist_markup( $playlist, $user_id, (int) $playlist['id'] === $expanded );
			}

			$body = array() === $rows
			? '<div class="mw-playlists__empty-state"><div class="mw-playlists__empty-icon" aria-hidden="true">♫</div><p class="mw-playlists__empty">' . esc_html__( 'شما هنوز هیچ فهرست پخشی ندارید. اولین مورد خود را در زیر ایجاد کنید.', 'music-wave-core' ) . '</p><p class="mw-playlists__empty-hint">' . esc_html__( 'فهرست‌های پخش برای شما خصوصی هستند و از کتابخانه موسیقی شما جدا هستند.', 'music-wave-core' ) . '</p></div>'
			: '<ul class="mw-playlists__list">' . implode( '', $rows ) . '</ul>';

			$header = '<header class="mw-playlists__header">'
			. '<div class="mw-playlists__heading">'
			. '<span class="mw-playlists__eyebrow"><span class="mw-playlists__eyebrow-icon" aria-hidden="true">♫</span> ' . esc_html__( 'فهرست‌های پخش شخصی', 'music-wave-core' ) . ' <span class="mw-playlists__eyebrow-divider" aria-hidden="true">·</span> <span class="mw-playlists__eyebrow-sub">' . esc_html__( 'جدا از کتابخانه موسیقی', 'music-wave-core' ) . '</span></span>'
			. '<h2 class="mw-playlists__title">' . esc_html( $heading ) . '</h2>'
			. '<p class="mw-playlists__intro">' . esc_html__( 'مجموعه‌های شخصی خود را ایجاد کنید، سفارش دهید و به‌اشتراک بگذارید — جدا از کتابخانه موسیقی خود. هر فهرست پخش را با یک کلیک پخش کنید.', 'music-wave-core' ) . '</p>'
			. '</div>'
			/* translators: %d: number of playlists. */
			. '<div class="mw-playlists__header-actions"><span class="mw-playlists__total" aria-label="' . esc_attr( sprintf( __( 'فهرست‌های پخش %d', 'music-wave-core' ), $total ) ) . '">' . esc_html( (string) $total ) . '<span class="mw-playlists__total-label"> ' . esc_html( /* translators: number of playlists. */ _n( 'فهرست پخش', 'فهرست‌های پخش', $total, 'music-wave-core' ) ) . '</span></span></div>'
			. '</header>';

			return '<section ' . BlockSupport::wrapper_attributes( 'mw-playlists' ) . ' data-mw-playlists>'
			. $header
			. $this->notice_markup()
			. $body
			. $this->create_form()
			. '</section>';
		} catch ( \Throwable $e ) {
			return '<section ' . BlockSupport::wrapper_attributes( 'mw-playlists mw-playlists--error' ) . '>'
				. '<div class="mw-playlists__empty"><p>' . esc_html__( 'در حال حاضر امکان نمایش فهرست‌های پخش وجود ندارد.', 'music-wave-core' ) . '</p></div>'
				. '</section>';
		}
	}

	/**
	 * Render the public community playlists browser.
	 *
	 * Shows only `public` playlists, with search, ordering and pagination.
	 * All data is viewer-filtered (only published releases) and cached as public.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_public( array $attributes ): string {
		try {
			$viewer_id    = get_current_user_id();
			$heading      = BlockSupport::optional_text_attribute( $attributes, 'heading', __( 'فهرست‌های پخش انجمن', 'music-wave-core' ), 'showHeading' );
			$intro        = BlockSupport::optional_text_attribute( $attributes, 'intro', __( 'فهرست‌های پخش تنظیم‌شده توسط شنوندگان دیگر را کشف کنید. هر فهرست پخش عمومی را می‌توان با یک کلیک پخش کرد.', 'music-wave-core' ) );
			$eyebrow      = BlockSupport::optional_text_attribute( $attributes, 'eyebrow', __( 'سرپرستی انجمن', 'music-wave-core' ) );
			$show_heading = '' !== $heading;
			$show_search  = ! isset( $attributes['showSearch'] ) || false !== $attributes['showSearch'];
			$show_count   = ! isset( $attributes['showCount'] ) || false !== $attributes['showCount'];
			$empty_msg    = BlockSupport::text_attribute( $attributes, 'emptyMessage', __( 'هنوز فهرست پخش عمومی وجود ندارد. اولین نفری باشید که یکی را به اشتراک می‌گذارد!', 'music-wave-core' ) );
			$placeholder  = BlockSupport::text_attribute( $attributes, 'searchPlaceholder', __( 'جست‌وجو در فهرست‌های پخش…', 'music-wave-core' ) );

			// Card display options shared by server markup and the JS renderer.
			$card_options = array(
				'showArt'        => ! isset( $attributes['showArt'] ) || false !== $attributes['showArt'],
				'showAuthor'     => ! isset( $attributes['showAuthor'] ) || false !== $attributes['showAuthor'],
				'showUpdated'    => ! isset( $attributes['showUpdated'] ) || false !== $attributes['showUpdated'],
				'showPlayButton' => ! isset( $attributes['showPlayButton'] ) || false !== $attributes['showPlayButton'],
				'showToggle'     => ! isset( $attributes['showToggle'] ) || false !== $attributes['showToggle'],
				'showPagination' => ! isset( $attributes['showPagination'] ) || false !== $attributes['showPagination'],
				'layout'         => 'grid',
				'columns'        => 3,
				'imageShape'     => 'square',
			);

			$items_to_show = isset( $attributes['itemsToShow'] ) ? absint( $attributes['itemsToShow'] ) : 12;
			$items_to_show = $items_to_show >= 4 && $items_to_show <= 24 ? $items_to_show : 12;
			$columns       = isset( $attributes['columns'] ) ? absint( $attributes['columns'] ) : 3;
			$columns       = $columns >= 2 && $columns <= 6 ? $columns : 3;
			$layout        = isset( $attributes['layout'] ) && in_array( $attributes['layout'], array( 'grid', 'scroll', 'list' ), true ) ? $attributes['layout'] : 'grid';
			$shape         = isset( $attributes['imageShape'] ) && in_array( $attributes['imageShape'], array( 'square', 'circle', 'landscape', 'portrait' ), true ) ? $attributes['imageShape'] : 'square';
			$orderby       = isset( $attributes['orderby'] ) && in_array( $attributes['orderby'], array( 'updated_at', 'created_at', 'title' ), true ) ? $attributes['orderby'] : 'updated_at';
			$style         = BlockSupport::style_variation( $attributes, array( 'cards', 'minimal', 'vinyl' ) );

			// The JS renderer re-applies layout, columns, and art shape when it
			// rebuilds cards after an instant search, so keep them in the payload.
			$card_options['layout']     = $layout;
			$card_options['columns']    = $columns;
			$card_options['imageShape'] = $shape;

			// Section header link, mirroring the release-shelf "See all" affordance.
			$section_url = isset( $attributes['sectionUrl'] ) && is_scalar( $attributes['sectionUrl'] ) ? esc_url_raw( (string) $attributes['sectionUrl'] ) : '';
			$more_label  = BlockSupport::text_attribute( $attributes, 'sectionLinkLabel', __( 'مشاهدهٔ همهٔ فهرست‌های پخش', 'music-wave-core' ) );

			// Read search & pagination from public query string (no nonce needed, read-only).
			$search = '';
			if ( isset( $_GET['mw-playlist-search'] ) && is_scalar( $_GET['mw-playlist-search'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$search = sanitize_text_field( wp_unslash( (string) $_GET['mw-playlist-search'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			$search = trim( $search );
			$search = mb_substr( $search, 0, 60 );

			$page = 1;
			if ( isset( $_GET['mw-playlists-page'] ) && is_scalar( $_GET['mw-playlists-page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$page = max( 1, absint( wp_unslash( (string) $_GET['mw-playlists-page'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}

			$total = $this->repository->count_public( $search );
			$pages = $items_to_show > 0 ? (int) ceil( $total / $items_to_show ) : 0;
			if ( $page > $pages && $pages > 0 ) {
				$page = $pages;
			}
			$offset    = ( $page - 1 ) * $items_to_show;
			$playlists = $this->repository->public_playlists( $items_to_show, $offset, $search, $orderby, $viewer_id );
			$is_editor = BlockSupport::is_editor_context();
			$sample_id = $is_editor ? EditorPreviewCatalog::requested_sample_id() : 0;
			if ( $is_editor && ( $sample_id > 0 || ( array() === $playlists && '' === $search ) ) ) {
				$playlists = EditorPreviewCatalog::resolve_public_playlists_for_editor(
					$this->repository,
					$items_to_show,
					$viewer_id,
					$sample_id
				);
				if ( $total < count( $playlists ) ) {
					$total = count( $playlists );
				}
			}

			// Single playlist requested via ?mw-playlist=ID (and optional ?mw-share=TOKEN).
			$expanded_id     = $this->requested_playlist();
			$share_token     = $this->requested_share_token();
			$featured_shared = '';
			if ( $expanded_id > 0 ) {
				$target_pl   = $this->repository->find( $expanded_id );
				$target_view = null !== $target_pl ? $this->repository->view( $expanded_id, $viewer_id, $share_token ) : null;
				if ( null !== $target_pl && null !== $target_view ) {
					$featured_shared = $this->shared_playlist_hero_markup( $target_pl, $target_view, $share_token );
				} else {
					$featured_shared = '<div class="mw-shared-playlist mw-shared-playlist--denied" role="status">'
						. '<p>' . esc_html__( 'این فهرست پخش خصوصی است یا یافت نشد.', 'music-wave-core' ) . '</p>'
						. '</div>';
				}
			}

			// Root classes: base component plus the selected editor style variation
			// (registered via register_block_style; default "cards" adds no class).
			$root_class = 'mw-public-playlists'
			. ( '' !== $style && 'cards' !== $style ? ' mw-public-playlists--' . $style : '' );

			$header_actions = '';
			if ( $show_count && $total > 0 ) {
				$header_actions .= '<span class="mw-public-playlists__total" aria-label="' . esc_attr( sprintf( /* translators: %d: number of public playlists. */ __( 'فهرست‌های پخش عمومی %d', 'music-wave-core' ), $total ) ) . '">' . esc_html( (string) $total ) . '</span>';
			}
			if ( '' !== $section_url ) {
				$header_actions .= '<a class="mw-public-playlists__more" href="' . esc_url( $section_url ) . '">' . esc_html( $more_label ) . '<span aria-hidden="true">&rarr;</span></a>';
			}

			$header = '';
			if ( $show_heading ) {
				$header = '<header class="mw-public-playlists__header">'
				. '<div class="mw-public-playlists__heading">'
				. '<span class="mw-public-playlists__eyebrow"><span class="mw-public-playlists__eyebrow-icon" aria-hidden="true">◎</span> ' . esc_html( $eyebrow ) . '</span>'
				. '<h2 class="mw-public-playlists__title">' . SectionHeader::title( $heading, isset( $attributes['icon'] ) && is_scalar( $attributes['icon'] ) ? trim( (string) $attributes['icon'] ) : '' ) . '</h2>'
				. ( '' !== $intro ? '<p class="mw-public-playlists__intro">' . esc_html( $intro ) . '</p>' : '' )
				. '</div>'
				. ( '' !== $header_actions ? '<div class="mw-public-playlists__header-actions">' . $header_actions . '</div>' : '' )
				. '</header>';
			}

			$toolbar = '';
			if ( $show_search ) {
				$toolbar = '<form class="mw-public-playlists__toolbar" method="get" action="' . esc_url( remove_query_arg( array( 'mw-playlist', 'mw-playlists-page' ) ) ) . '" role="search" aria-label="' . esc_attr__( 'فهرست‌های پخش عمومی را جست‌وجو', 'music-wave-core' ) . '" data-mw-public-search-form data-orderby="' . esc_attr( $orderby ) . '" data-per-page="' . esc_attr( (string) $items_to_show ) . '">'
				. '<label class="screen-reader-text" for="mw-playlist-search">' . esc_html__( 'جست‌وجو در فهرست‌های پخش', 'music-wave-core' ) . '</label>'
				. '<input id="mw-playlist-search" type="search" name="mw-playlist-search" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr( $placeholder ) . '" maxlength="60" data-mw-public-search-input autocomplete="off" spellcheck="false">'
				. '<button type="submit">' . esc_html__( 'جست‌وجو', 'music-wave-core' ) . '</button>'
				. ( '' !== $search ? '<a class="mw-public-playlists__reset" href="' . esc_url( remove_query_arg( array( 'mw-playlist-search', 'mw-playlists-page' ) ) ) . '" data-mw-public-clear>' . esc_html__( 'پاک کردن', 'music-wave-core' ) . '</a>' : '' )
				. '<span class="mw-public-playlists__live" role="status" aria-live="polite" aria-atomic="true" data-mw-public-live></span>'
				. '</form>';
				// Preserve other query args? Keep it simple.
			}

			if ( array() === $playlists ) {
				if ( '' !== $featured_shared ) {
					return '<section ' . BlockSupport::wrapper_attributes( $root_class ) . ' data-mw-public-playlists data-mw-public-options=\'' . esc_attr( (string) wp_json_encode( $card_options ) ) . '\'>'
					. $featured_shared . '</section>';
				}
				$body = '<div class="mw-public-playlists__empty"><p>' . esc_html( $empty_msg ) . '</p>';
				if ( '' !== $search ) {
					$body .= '<p><a href="' . esc_url( remove_query_arg( 'mw-playlist-search' ) ) . '" data-mw-public-clear>' . esc_html__( 'پاک کردن جست‌وجو', 'music-wave-core' ) . '</a></p>';
				}
				$body .= '</div>';

				return '<section ' . BlockSupport::wrapper_attributes( $root_class ) . ' data-mw-public-playlists data-mw-public-options=\'' . esc_attr( wp_json_encode( $card_options ) ) . '\'>'
				. $header . $toolbar . $body . '</section>';
			}

			$cards = array();
			foreach ( $playlists as $playlist ) {
				$cards[] = $this->public_card_markup( $playlist, false, $card_options );
			}

			// Column counts only describe a real grid; scroll and list are
			// single-axis layouts, so printing a column modifier there would
			// fight their own `grid-template-columns`.
			$grid_class = 'mw-public-playlists__grid mw-public-playlists__grid--' . $layout;
			if ( 'grid' === $layout ) {
				$grid_class .= ' mw-public-playlists__grid--columns-' . $columns;
			}
			$body = '<div class="' . esc_attr( $grid_class ) . '">' . implode( '', $cards ) . '</div>';

			$pagination = '';
			if ( ! empty( $card_options['showPagination'] ) && $pages > 1 ) {
				$pagination = $this->public_pagination_markup( $page, $pages, $search );
			}

			return '<section ' . BlockSupport::wrapper_attributes( $root_class ) . ' data-mw-public-playlists data-mw-public-options=\'' . esc_attr( (string) wp_json_encode( $card_options ) ) . '\'>'
			. $featured_shared . $header . $toolbar . $body . $pagination . '</section>';
		} catch ( \Throwable $e ) {
			// Safe fallback for editor preview – never throw.
			$fallback_heading = BlockSupport::text_attribute( $attributes, 'heading', __( 'فهرست‌های پخش عمومی', 'music-wave-core' ) );
			return '<section ' . BlockSupport::wrapper_attributes( 'mw-public-playlists mw-public-playlists--error' ) . '>'
				. '<div class="mw-public-playlists__empty"><p>' . esc_html( $fallback_heading ) . ' — ' . esc_html__( 'در حال حاضر فهرست پخش عمومی برای نمایش وجود ندارد.', 'music-wave-core' ) . '</p></div>'
				. '</section>';
		}
	}

	/**
	 * One public card: art grid + title + author + play all + track toggle (for anon viewer).
	 *
	 * @param array<string, mixed>             $playlist Normalized public playlist from repository.
	 * @param bool                             $expanded Whether the track panel renders open.
	 * @param array<string, mixed>|null $options  Display toggles and layout options from block attributes.
	 */
	private function public_card_markup( array $playlist, bool $expanded, ?array $options = null ): string {
		$options     = is_array( $options ) ? $options : array(
			'showArt'        => true,
			'showAuthor'     => true,
			'showUpdated'    => true,
			'showPlayButton' => true,
			'showToggle'     => true,
			'imageShape'     => 'square',
		);
		$playlist_id = (int) $playlist['id'];
		$title       = (string) $playlist['title'];
		$author      = isset( $playlist['author_name'] ) ? (string) $playlist['author_name'] : '';
		$count       = isset( $playlist['count'] ) ? (int) $playlist['count'] : 0;
		$updated     = isset( $playlist['updated_at'] ) ? (int) $playlist['updated_at'] : 0;
		$panel_id    = 'mw-public-playlist-panel-' . $playlist_id;
		$viewer_id   = get_current_user_id();
		$shape       = isset( $options['imageShape'] ) && is_string( $options['imageShape'] ) ? $options['imageShape'] : 'square';

		// Art grid using viewer-filtered items (only published releases). The
		// shape modifier mirrors the release-shelf art variants and is appended
		// only when non-default (square). The cover stack sits on a padded
		// "sleeve plate" that the theme frames as a layered deck — the main
		// cover returns behind itself as a dark glass sleeve — and carries the
		// decorative master stamp whenever the playlist really holds tracks.
		$covers = ! empty( $options['showArt'] ) ? $this->playlist_covers_markup( $playlist, $viewer_id, true ) : '';
		$art    = '';
		if ( '' !== $covers ) {
			// MASTER stamp removed per spec – clean minimal card.
			$art = '<div class="mw-public-playlists__art' . ( 'square' !== $shape ? ' mw-public-playlists__art--' . esc_attr( $shape ) : '' ) . '" aria-hidden="true">' . $covers . '</div>';
		}

		// Public playlists never expose share_token; play uses public queue.
		$play_button = '';
		if ( ! empty( $options['showPlayButton'] ) ) {
			$play_button = '<button type="button" class="mw-public-playlists__play" data-mw-playlist-play data-playlist-id="' . esc_attr( (string) $playlist_id ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: playlist title. */ __( 'پخش تمام قطعه‌ها در %s', 'music-wave-core' ), $title ) ) . '"' . ( 0 === $count ? ' disabled aria-disabled="true"' : '' ) . '><span class="mw-playlists__play-icon" aria-hidden="true">▶</span><span>' . esc_html__( 'پخش همه', 'music-wave-core' ) . '</span><span class="mw-public-playlists__play-count" aria-hidden="true">' . esc_html( (string) $count ) . '</span></button>';
		}

		$toggle = '';
		if ( ! empty( $options['showToggle'] ) ) {
			$toggle = '<button type="button" class="mw-public-playlists__toggle" aria-expanded="' . ( $expanded ? 'true' : 'false' ) . '" aria-controls="' . esc_attr( $panel_id ) . '" data-mw-public-toggle data-playlist-id="' . esc_attr( (string) $playlist_id ) . '">' . esc_html( $expanded ? __( 'مخفی کردن قطعه‌ها', 'music-wave-core' ) : __( 'نمایش قطعه‌ها', 'music-wave-core' ) ) . '</button>';
		}

		$meta = $this->public_meta_markup(
			! empty( $options['showAuthor'] ) ? $author : '',
			$count,
			! empty( $options['showUpdated'] ) ? $updated : 0
		);

		$tracks = '';
		if ( $expanded ) {
			$view = $this->repository->view( $playlist_id, $viewer_id );
			if ( null !== $view ) {
				$tracks = '<div class="mw-public-playlists__tracks">' . $this->public_tracks_markup( $playlist_id, $view ) . '</div>';
			}
		}

		// Card shell: artwork plate + copy sit inside one framed surface, and the
		// expandable track panel stays a sibling so it can span the full width.
		return '<article class="mw-public-playlists__card" data-mw-playlist-id="' . esc_attr( (string) $playlist_id ) . '">'
			. '<div class="mw-public-playlists__frame">'
			. $art
			. '<div class="mw-public-playlists__main">'
			. '<h3 class="mw-public-playlists__name">' . esc_html( $title ) . '</h3>'
			. $meta
			. '<div class="mw-public-playlists__actions">' . $play_button . $toggle . '</div>'
			. '</div>'
			. '</div>'
			. '<div class="mw-public-playlists__panel" id="' . esc_attr( $panel_id ) . '"' . ( $expanded ? '' : ' hidden' ) . ' data-mw-public-panel data-playlist-id="' . esc_attr( (string) $playlist_id ) . '"' . ( $expanded ? ' data-loaded="true"' : '' ) . '>' . $tracks . '</div>'
			. '</article>';
	}

	/**
	 * @param array<string, mixed> $view Playlist view payload.
	 */
	private function public_tracks_markup( int $playlist_id, array $view, string $share_token = '' ): string {
		$items = isset( $view['items'] ) && is_array( $view['items'] ) ? $view['items'] : array();
		if ( array() === $items ) {
			return '<p class="mw-public-playlists__empty">' . esc_html__( 'این فهرست پخش خالی است.', 'music-wave-core' ) . '</p>';
		}
		$share_attr = '' !== $share_token ? ' data-mw-share="' . esc_attr( $share_token ) . '"' : '';
		$rows       = array();
		foreach ( $items as $index => $item ) {
			$release_id = (int) $item['release_id'];
			$title      = get_the_title( $release_id );
			$title      = '' !== $title ? $title : __( 'انتشار بدون عنوان', 'music-wave-core' );
			$link       = get_permalink( $release_id );

			// Artist taxonomy linked – per spec: title and taxonomy (artist/category) inside track-title should be linked.
			$artist_terms = get_the_terms( $release_id, 'mw_artist' );
			$artist_links = array();
			if ( is_array( $artist_terms ) ) {
				foreach ( $artist_terms as $term ) {
					if ( ! $term instanceof \WP_Term ) {
						continue;
					}
					$term_link = get_term_link( $term );
					if ( is_wp_error( $term_link ) ) {
						continue;
					}
					$artist_links[] = '<a href="' . esc_url( $term_link ) . '">' . esc_html( $term->name ) . '</a>';
				}
			}
			if ( array() === $artist_links ) {
				// Fallback to names when terms not available.
				$artists = wp_get_post_terms( $release_id, 'mw_artist', array( 'fields' => 'names' ) );
				if ( is_array( $artists ) && ! empty( $artists ) ) {
					foreach ( $artists as $name ) {
						$artist_links[] = esc_html( $name );
					}
				}
			}
			$artist_m = '' !== implode( '', $artist_links ) ? '<span class="mw-public-playlists__track-artist">' . implode( ', ', $artist_links ) . '</span>' : '';

			// Track art and fallback removed per spec – clean minimal row; single-track button plays only this release.
			$rows[] = '<div class="mw-public-playlists__track" data-mw-playlist-track="' . esc_attr( (string) $release_id ) . '"><span class="mw-public-playlists__position">' . esc_html( (string) ( $index + 1 ) ) . '</span><span class="mw-public-playlists__track-title">' . ( is_string( $link ) && '' !== $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . $artist_m . '</span>' . $this->track_play_button_markup( $release_id, $title, 'mw-public-playlists__track-play', $share_attr ) . '</div>';
		}

		return '<div class="mw-public-playlists__tracks-list">' . implode( '', $rows ) . '</div>';
	}

	/**
	 * Shared metadata line (`author · count · time`) for public & shared playlist surfaces.
	 */
	private function public_meta_markup( string $author, int $count, int $updated ): string {
		$meta = '<p class="mw-public-playlists__meta">';
		if ( '' !== $author ) {
			$meta .= '<span class="mw-public-playlists__author">' . esc_html( $author ) . '</span><span class="mw-public-playlists__dot" aria-hidden="true">·</span>';
		}
		$meta .= '<span class="mw-public-playlists__count"><span class="mw-public-playlists__count-icon" aria-hidden="true">♪</span>' . esc_html(
			sprintf(
				/* translators: %d: number of releases. */
				_n( '%d قطعه', '%d قطعه', $count, 'music-wave-core' ),
				$count
			)
		) . '</span>';
		if ( $updated > 0 ) {
			$meta .= '<span class="mw-public-playlists__dot" aria-hidden="true">·</span><time datetime="' . esc_attr( gmdate( 'c', $updated ) ) . '">' . esc_html( $this->human_time_diff( $updated ) ) . '</time>';
		}
		$meta .= '</p>';

		return $meta;
	}

	/**
	 * Shared single-track play trigger for playlist track rows.
	 */
	private function track_play_button_markup( int $release_id, string $title, string $class_name, string $extra_attrs = '' ): string {
		/* translators: %s: release title. */
		$aria_label = sprintf( __( 'پخش %s', 'music-wave-core' ), $title );

		return '<button type="button" class="' . esc_attr( $class_name . ' mw-card-play' ) . '" data-mw-release-id="' . esc_attr( (string) $release_id ) . '"' . $extra_attrs . ' aria-pressed="false" aria-label="' . esc_attr( $aria_label ) . '"><span class="mw-card-play__icon" aria-hidden="true">▶</span></button>';
	}

	private function public_pagination_markup( int $page, int $pages, string $search ): string {
		if ( $pages <= 1 ) {
			return '';
		}
		$links = array();
		$base  = remove_query_arg( 'mw-playlist' );
		for ( $i = 1; $i <= $pages; $i++ ) {
			if ( $i === $page ) {
				$links[] = '<span class="mw-public-playlists__page is-active" aria-current="page">' . esc_html( (string) $i ) . '</span>';
			} else {
				$args = array( 'mw-playlists-page' => (string) $i );
				if ( '' !== $search ) {
					$args['mw-playlist-search'] = $search;
				}
				$links[] = '<a class="mw-public-playlists__page" href="' . esc_url( add_query_arg( $args, $base ) ) . '" data-mw-public-page data-page="' . esc_attr( (string) $i ) . '">' . esc_html( (string) $i ) . '</a>';
			}
		}
		/* translators: 1: current page, 2: total pages. */
		$label = sprintf( __( 'صفحه فهرست‌های پخش عمومی %1$d از %2$d', 'music-wave-core' ), $page, $pages );

		return '<nav class="mw-public-playlists__pagination" aria-label="' . esc_attr__( 'صفحات فهرست پخش عمومی', 'music-wave-core' ) . '"><span class="screen-reader-text">' . esc_html( $label ) . '</span>' . implode( '', $links ) . '</nav>';
	}

	private function human_time_diff( int $timestamp ): string {
		$diff = time() - $timestamp;
		if ( $diff < 60 ) {
			return __( 'همین الان', 'music-wave-core' );
		}
		if ( $diff < 3600 ) {
			return sprintf( /* translators: %d: minutes. */ _n( '%d دقیقه پیش', '%d دقیقه پیش', (int) floor( $diff / 60 ), 'music-wave-core' ), (int) floor( $diff / 60 ) );
		}
		if ( $diff < 86400 ) {
			return sprintf( /* translators: %d: hours. */ _n( '%d ساعت قبل', '%d ساعت پیش', (int) floor( $diff / 3600 ), 'music-wave-core' ), (int) floor( $diff / 3600 ) );
		}
		if ( $diff < 30 * 86400 ) {
			return sprintf( /* translators: %d: days. */ _n( '%d روز پیش', '%d روز پیش', (int) floor( $diff / 86400 ), 'music-wave-core' ), (int) floor( $diff / 86400 ) );
		}

		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Ensure the dedicated public playlists page exists.
	 *
	 * @return void
	 */
	public function ensure_public_page(): void {
		if ( wp_doing_cron() ) {
			return;
		}
		if ( null !== get_page_by_path( 'playlists' ) ) {
			return;
		}
		// Avoid race on every request: use a transient lock.
		if ( false !== get_transient( 'mw_playlists_page_check' ) ) {
			return;
		}
		set_transient( 'mw_playlists_page_check', 1, 12 * HOUR_IN_SECONDS );
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => __( 'فهرست‌های پخش', 'music-wave-core' ),
				'post_name'    => 'playlists',
				'post_content' => '<!-- wp:music-wave/public-playlists {"heading":"فهرست‌های پخش عمومی","itemsToShow":12,"columns":3} /-->',
				'post_status'  => 'publish',
			)
		);
		if ( ! is_wp_error( $page_id ) && $page_id > 0 ) {
			delete_transient( 'mw_playlists_page_check' );
		}
	}

	/**
	 * Dedicated guest-ready hero & playable tracklist for a shared playlist (`?mw-playlist=ID`).
	 *
	 * @param array<string, mixed> $playlist Normalized playlist row.
	 * @param array<string, mixed> $view     Viewer-filtered projection from `PlaylistRepository::view()`.
	 */
	private function shared_playlist_hero_markup( array $playlist, array $view, string $share_token = '' ): string {
		$playlist_id = (int) $playlist['id'];
		$title       = (string) $view['title'];
		$visibility  = (string) $view['visibility'];
		$author      = isset( $view['author_name'] ) ? (string) $view['author_name'] : '';
		$count       = isset( $view['count'] ) ? (int) $view['count'] : 0;
		$updated     = isset( $view['updated_at'] ) ? (int) $view['updated_at'] : 0;
		$viewer_id   = get_current_user_id();
		$share_url   = isset( $view['share_url'] ) && '' !== (string) $view['share_url']
			? (string) $view['share_url']
			: $this->repository->public_url( $playlist_id, $visibility, $share_token );
		$covers      = $this->playlist_covers_markup( $playlist, $viewer_id, true );
		$share_attr  = PlaylistRepository::VISIBILITY_UNLISTED === $visibility && '' !== $share_token
			? ' data-mw-share="' . esc_attr( $share_token ) . '"'
			: '';

		$play_btn = '<button type="button" class="mw-public-playlists__play" data-mw-playlist-play data-playlist-id="' . esc_attr( (string) $playlist_id ) . '"' . $share_attr . ' aria-label="' . esc_attr( sprintf( /* translators: %s: playlist title. */ __( 'پخش تمام قطعه‌ها در %s', 'music-wave-core' ), $title ) ) . '"' . ( 0 === $count ? ' disabled aria-disabled="true"' : '' ) . '><span class="mw-playlists__play-icon" aria-hidden="true">▶</span><span>' . esc_html__( 'پخش همه', 'music-wave-core' ) . '</span><span class="mw-public-playlists__play-count" aria-hidden="true">' . esc_html( (string) $count ) . '</span></button>';

		$copy_btn = '' !== $share_url
			? '<button type="button" class="mw-playlists__share-copy" data-mw-copy-share="' . esc_attr( $share_url ) . '">' . esc_html__( 'کپی پیوند اشتراک', 'music-wave-core' ) . '</button>'
			: '';

		$meta = $this->public_meta_markup( $author, $count, $updated );

		$track_share = PlaylistRepository::VISIBILITY_UNLISTED === $visibility ? $share_token : '';

		return '<article class="mw-shared-playlist" data-mw-shared-playlist="' . esc_attr( (string) $playlist_id ) . '" data-mw-playlist-id="' . esc_attr( (string) $playlist_id ) . '"' . $share_attr . '>'
			. '<div class="mw-shared-playlist__hero">'
			. ( '' !== $covers ? '<div class="mw-public-playlists__art mw-shared-playlist__art" aria-hidden="true">' . $covers . '</div>' : '' )
			. '<div class="mw-shared-playlist__body">'
			. '<span class="mw-public-playlists__eyebrow"><span aria-hidden="true">♫</span> ' . esc_html__( 'فهرست پخش اشتراک‌گذاری‌شده', 'music-wave-core' ) . '</span>'
			. '<h2 class="mw-shared-playlist__title">' . esc_html( $title ) . '</h2>'
			. $meta
			. '<div class="mw-public-playlists__actions mw-shared-playlist__actions">' . $play_btn . $copy_btn . '</div>'
			. '</div>'
			. '</div>'
			. '<div class="mw-shared-playlist__tracks">' . $this->public_tracks_markup( $playlist_id, $view, $track_share ) . '</div>'
			. '</article>';
	}

	private const PICKER_ICON_SVG = '<svg class="mw-nav-icon__svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 6h11M4 12h11M4 18h6"></path><path d="M19 6v10"></path><circle cx="16.5" cy="17" r="2.5"></circle></svg>';

	/**
	 * Render the add-to-playlist picker for one release.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_picker( array $attributes ): string {
		$release_id  = ReleaseResolver::resolve( $attributes, $this->render_context );
		$mode        = ActionButtonStyle::resolve( $attributes, ActionButtonStyle::MODE_ICON );
		$show_label  = ActionButtonStyle::shows_label( $mode );
		$label       = BlockSupport::text_attribute( $attributes, 'label', __( 'افزودن به فهرست پخش', 'music-wave-core' ) );
		$root_class  = 'mw-playlist-picker mw-playlist-picker--' . $mode;
		$btn_classes = ActionButtonStyle::button_classes(
			$attributes,
			'mw-playlist-picker__trigger',
			ActionButtonStyle::MODE_ICON,
			ActionButtonStyle::VARIANT_SURFACE
		);
		$icon_html   = $this->picker_trigger_icon_markup( $attributes );

		if ( $release_id < 1 ) {
			if ( ! BlockSupport::is_editor_context() ) {
				return '';
			}
			$label_class = $show_label ? 'mw-playlist-picker__trigger-label' : 'mw-playlist-picker__trigger-label screen-reader-text';
			return '<div ' . BlockSupport::wrapper_attributes( $root_class . ' mw-playlist-picker--placeholder' ) . '>'
				. '<button type="button" class="' . esc_attr( $btn_classes ) . '" aria-label="' . esc_attr( $label ) . '">'
				. $icon_html
				. '<span class="' . esc_attr( $label_class ) . '">' . esc_html( $label ) . '</span>'
				. '</button>'
				. '</div>';
		}

		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			$guest_classes = ActionButtonStyle::button_classes(
				$attributes,
				'mw-playlist-picker__trigger mw-playlist-picker__trigger--guest',
				ActionButtonStyle::MODE_ICON,
				ActionButtonStyle::VARIANT_SURFACE
			);
			$label_class   = $show_label ? 'mw-playlist-picker__trigger-label' : 'mw-playlist-picker__trigger-label screen-reader-text';
			return '<div ' . BlockSupport::wrapper_attributes( $root_class . ' mw-playlist-picker--guest' ) . '>'
				. '<a class="' . esc_attr( $guest_classes ) . '" href="' . esc_url( AuthPolicy::login_url( BlockSupport::current_url() ) ) . '" aria-label="' . esc_attr( $label ) . '">'
				. $icon_html
				. '<span class="' . esc_attr( $label_class ) . '">' . esc_html( $label ) . '</span>'
				. '</a>'
				. '</div>';
		}

		if ( BlockSupport::is_editor_context() ) {
			$panel_id = 'mw-playlist-picker-panel-' . $release_id;
			$trigger  = $this->picker_trigger_markup( $label, $panel_id, 0, $mode, $btn_classes, $icon_html );
			return '<div ' . BlockSupport::wrapper_attributes( $root_class ) . ' data-mw-playlist-picker data-release-id="' . esc_attr( (string) $release_id ) . '">'
				. $trigger
				. '</div>';
		}

		$playlists = $this->repository->for_user( $user_id, PlaylistRepository::MAX_PLAYLISTS );
		$field_id  = 'mw-playlist-picker-' . $release_id;
		$notice    = $this->notice_markup();

		// Modern picker: trigger + popover panel (JS) + fallback form (no-JS & test parity).
		if ( array() === $playlists ) {
			// No playlists yet: inline create panel + fallback form.
			$panel_id = 'mw-playlist-picker-panel-' . $release_id;
			$trigger  = $this->picker_trigger_markup( $label, $panel_id, 0, $mode, $btn_classes, $icon_html );

			$js_panel = '<div id="' . esc_attr( $panel_id ) . '" class="mw-playlist-picker__panel" hidden role="dialog" aria-label="' . esc_attr__( 'ایجاد فهرست پخش', 'music-wave-core' ) . '" data-mw-picker-panel>'
				. '<div class="mw-playlist-picker__panel-header"><strong>' . esc_html__( 'اولین فهرست پخش خود را بسازید', 'music-wave-core' ) . '</strong><button type="button" class="mw-playlist-picker__close" aria-label="' . esc_attr__( 'بستن', 'music-wave-core' ) . '" data-mw-picker-close>×</button></div>'
				. '<p class="mw-playlist-picker__hint">' . esc_html__( 'نامی‌انتخاب کنید تا این انتشار را برای شما اضافه کنیم.', 'music-wave-core' ) . '</p>'
				. '<form class="mw-playlist-picker__create mw-playlist-picker__create--js" data-mw-picker-create data-release-id="' . esc_attr( (string) $release_id ) . '">'
				. '<label for="' . esc_attr( $field_id ) . '-js">' . esc_html__( 'نام فهرست پخش جدید', 'music-wave-core' ) . '</label>'
				. '<div class="mw-playlist-picker__create-row"><input id="' . esc_attr( $field_id ) . '-js" type="text" maxlength="' . esc_attr( (string) PlaylistRepository::MAX_TITLE ) . '" required placeholder="' . esc_attr__( 'به عنوان مثال رانندگی در اواخر شب', 'music-wave-core' ) . '"><button type="submit">' . esc_html__( 'ایجاد و اضافه کردن', 'music-wave-core' ) . '</button></div>'
				. '</form>'
				. '<div class="mw-playlist-picker__status" role="status" aria-live="polite" data-mw-picker-status></div>'
				. '</div>';

			$fallback = '<form class="mw-playlist-picker__form mw-playlist-picker__form--fallback" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. $this->hidden_fields( 'create' )
				. '<label for="' . esc_attr( $field_id ) . '">' . esc_html__( 'نام فهرست پخش جدید', 'music-wave-core' ) . '</label>'
				. '<input id="' . esc_attr( $field_id ) . '" type="text" name="mw_title" maxlength="' . esc_attr( (string) PlaylistRepository::MAX_TITLE ) . '" required>'
				. '<button type="submit">' . esc_html__( 'ایجاد فهرست پخش', 'music-wave-core' ) . '</button>'
				. '</form>';

			return '<div ' . BlockSupport::wrapper_attributes( $root_class ) . ' data-mw-playlist-picker data-release-id="' . esc_attr( (string) $release_id ) . '">'
				. $notice
				. $trigger
				. $js_panel
				. '<noscript>' . $fallback . '</noscript>'
				. '<div class="mw-playlist-picker__fallback" data-mw-picker-fallback>' . $fallback . '</div>'
				. '</div>';
		}

		$options = '';
		foreach ( $playlists as $playlist ) {
			$options .= '<option value="' . esc_attr( (string) $playlist['id'] ) . '">' . esc_html( (string) $playlist['title'] ) . '</option>';
		}

		$panel_id = 'mw-playlist-picker-panel-' . $release_id;
		$trigger  = $this->picker_trigger_markup( $label, $panel_id, count( $playlists ), $mode, $btn_classes, $icon_html );

		$js_options = '';
		foreach ( $playlists as $playlist ) {
			$pid   = (int) $playlist['id'];
			$title = (string) $playlist['title'];
			$count = isset( $playlist['count'] ) ? (int) $playlist['count'] : 0;
			/* translators: %d: number of tracks in playlist. */
			$js_options .= '<button type="button" class="mw-playlist-picker__option" role="option" aria-selected="false" data-mw-picker-option data-playlist-id="' . esc_attr( (string) $pid ) . '"><span class="mw-playlist-picker__option-title">' . esc_html( $title ) . '</span><span class="mw-playlist-picker__option-meta">' . esc_html( sprintf( _n( '%d قطعه', '%d قطعه', $count, 'music-wave-core' ), $count ) ) . '</span><span class="mw-playlist-picker__option-check" aria-hidden="true">✓</span></button>';
		}

		$js_panel = '<div id="' . esc_attr( $panel_id ) . '" class="mw-playlist-picker__panel" hidden role="dialog" aria-label="' . esc_attr( $label ) . '" data-mw-picker-panel>'
			. '<div class="mw-playlist-picker__panel-header"><strong>' . esc_html( $label ) . '</strong><button type="button" class="mw-playlist-picker__close" aria-label="' . esc_attr__( 'بستن', 'music-wave-core' ) . '" data-mw-picker-close>×</button></div>'
			. '<div class="mw-playlist-picker__options" role="listbox" aria-label="' . esc_attr__( 'فهرست‌های پخش شما', 'music-wave-core' ) . '" data-mw-picker-options>' . $js_options . '</div>'
			. '<div class="mw-playlist-picker__divider"><span>' . esc_html__( 'یا', 'music-wave-core' ) . '</span></div>'
			. '<form class="mw-playlist-picker__create mw-playlist-picker__create--js" data-mw-picker-create data-release-id="' . esc_attr( (string) $release_id ) . '">'
			. '<label for="' . esc_attr( $field_id ) . '-js">' . esc_html__( 'نام فهرست پخش جدید', 'music-wave-core' ) . '</label>'
			. '<div class="mw-playlist-picker__create-row"><input id="' . esc_attr( $field_id ) . '-js" type="text" maxlength="' . esc_attr( (string) PlaylistRepository::MAX_TITLE ) . '" placeholder="' . esc_attr__( 'فهرست پخش جدید…', 'music-wave-core' ) . '" required><button type="submit">' . esc_html__( 'ایجاد و اضافه کردن', 'music-wave-core' ) . '</button></div>'
			. '</form>'
			. '<div class="mw-playlist-picker__status" role="status" aria-live="polite" data-mw-picker-status></div>'
			. '</div>';

		$fallback = '<form class="mw-playlist-picker__form mw-playlist-picker__form--fallback" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. $this->hidden_fields( 'add-item' )
			. '<input type="hidden" name="mw_release_id" value="' . esc_attr( (string) $release_id ) . '">'
			. '<label for="' . esc_attr( $field_id ) . '">' . esc_html( $label ) . '</label>'
			. '<select id="' . esc_attr( $field_id ) . '" name="mw_playlist_id">' . $options . '</select>'
			. '<button type="submit">' . esc_html__( 'افزودن', 'music-wave-core' ) . '</button>'
			. '</form>';

		return '<div ' . BlockSupport::wrapper_attributes( $root_class ) . ' data-mw-playlist-picker data-release-id="' . esc_attr( (string) $release_id ) . '">'
			. $notice
			. $trigger
			. $js_panel
			. '<noscript>' . $fallback . '</noscript>'
			. '<div class="mw-playlist-picker__fallback" data-mw-picker-fallback>' . $fallback . '</div>'
			. '</div>';
	}

	/**
	 * Render trigger icon markup from custom icon attribute or default playlist glyph.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function picker_trigger_icon_markup( array $attributes = array() ): string {
		$raw = isset( $attributes['icon'] ) && is_scalar( $attributes['icon'] ) ? trim( (string) $attributes['icon'] ) : '';
		$svg = ( '' === $raw || 'plus' === $raw )
			? self::PICKER_ICON_SVG
			: ActionButtonStyle::render_icon( $attributes, 'plus', 20 );
		return '<span class="mw-playlist-picker__trigger-icon" aria-hidden="true">' . $svg . '</span>';
	}

	private function picker_trigger_markup(
		string $label,
		string $panel_id,
		int $count,
		string $mode = ActionButtonStyle::MODE_ICON,
		string $btn_classes = '',
		string $icon_html = ''
	): string {
		$badge       = $count > 0
			? '<span class="mw-playlist-picker__trigger-count" aria-hidden="true">' . esc_html( (string) $count ) . '</span>'
			: '';
		$show_label  = ActionButtonStyle::shows_label( $mode );
		$label_class = $show_label ? 'mw-playlist-picker__trigger-label' : 'mw-playlist-picker__trigger-label screen-reader-text';
		$classes     = '' !== $btn_classes
			? $btn_classes
			: 'mw-action-button mw-action-button--' . $mode . ' mw-action-button--surface mw-action-button--md mw-action-button--pill mw-playlist-picker__trigger mw-playlist-picker__trigger--' . $mode;
		$icon_out    = '' !== $icon_html ? $icon_html : $this->picker_trigger_icon_markup();

		return '<button type="button" class="' . esc_attr( $classes ) . '" aria-label="' . esc_attr( $label ) . '" aria-expanded="false" aria-controls="' . esc_attr( $panel_id ) . '" aria-haspopup="dialog" data-mw-picker-trigger>'
			. $icon_out
			. '<span class="' . esc_attr( $label_class ) . '">' . esc_html( $label ) . '</span>'
			. $badge
			. '</button>';
	}

	/**
	 * One playlist row with its controls and, when expanded, its items.
	 *
	 * @param array<string, mixed> $playlist Normalized playlist.
	 */
	private function playlist_markup( array $playlist, int $user_id, bool $expanded ): string {
		$playlist_id = (int) $playlist['id'];
		$title       = (string) $playlist['title'];
		$visibility  = (string) $playlist['visibility'];
		$count       = isset( $playlist['count'] ) ? (int) $playlist['count'] : 0;
		$panel_id    = 'mw-playlist-panel-' . $playlist_id;

		$items_markup = '';
		if ( $expanded ) {
			$view         = $this->repository->view( $playlist_id, $user_id );
			$items_markup = null !== $view ? $this->items_markup( $playlist_id, $view ) : '';
		}

		$toggle = '<a class="mw-playlists__toggle" href="' . esc_url( $this->playlist_url( $expanded ? 0 : $playlist_id ) ) . '#' . esc_attr( $panel_id ) . '" aria-expanded="' . ( $expanded ? 'true' : 'false' ) . '" aria-controls="' . esc_attr( $panel_id ) . '">'
			. esc_html( $expanded ? __( 'مخفی کردن قطعه‌ها', 'music-wave-core' ) : __( 'نمایش قطعه‌ها', 'music-wave-core' ) )
			. '</a>';

		$covers      = $this->playlist_covers_markup( $playlist, $user_id );
		$play_button = $this->play_all_button( $playlist, $count );

		return '<li class="mw-playlists__item" data-mw-playlist-id="' . esc_attr( (string) $playlist_id ) . '">'
			. '<div class="mw-playlists__card">'
			. '<div class="mw-playlists__art" aria-hidden="true">' . $covers . '</div>'
			. '<div class="mw-playlists__main">'
			. '<div class="mw-playlists__row">'
			. '<h3 class="mw-playlists__name" data-mw-playlist-name="' . esc_attr( (string) $playlist_id ) . '">' . esc_html( $title ) . '</h3>'
			. '<p class="mw-playlists__meta">'
			. '<span class="mw-playlists__badge mw-playlists__badge--' . esc_attr( $visibility ) . '">' . esc_html( $this->visibility_label( $visibility ) ) . '</span> '
			. '<span class="mw-playlists__count" data-mw-playlist-count="' . esc_attr( (string) $playlist_id ) . '">' . esc_html(
				sprintf(
					/* translators: %d: number of releases in the playlist. */
					_n( '%d انتشار', '%d انتشار', $count, 'music-wave-core' ),
					$count
				)
			) . '</span>'
			. '</p>'
			. '</div>'
			. '<div class="mw-playlists__actions">' . $play_button . $toggle . '</div>'
			. '</div>'
			. '</div>'
			. $this->settings_form( $playlist )
			. $this->share_markup( $playlist )
			. '<div class="mw-playlists__panel" id="' . esc_attr( $panel_id ) . '"' . ( $expanded ? '' : ' hidden' ) . '>' . $items_markup . '</div>'
			. '</li>';
	}

	/**
	 * 2x2 cover grid for a playlist card (first 4 readable items).
	 *
	 * With `$layered` the public card's plate also gets its back sleeve: the
	 * same main cover that fronts the stack, reused by the theme as the dark
	 * glass duplicate peeking out behind it.
	 *
	 * @param array<string, mixed> $playlist Normalized playlist.
	 * @param int                  $user_id  Viewer (0 for anonymous).
	 * @param bool                 $layered  Emit the layered back sleeve markup.
	 */
	private function playlist_covers_markup( array $playlist, int $user_id, bool $layered = false ): string {
		$playlist_id = (int) $playlist['id'];
		if ( ! empty( $playlist['release_ids'] ) && is_array( $playlist['release_ids'] ) ) {
			$ids = array_slice( array_values( array_map( 'absint', $playlist['release_ids'] ) ), 0, 4 );
		} else {
			$items = $playlist_id > 0 ? $this->repository->items_for_viewer( $playlist_id, $user_id ) : array();
			$ids   = array_slice(
				array_values(
					array_map(
						static function ( array $item ): int {
							return absint( $item['release_id'] );
						},
						$items
					)
				),
				0,
				4
			);
		}

		if ( array() === $ids ) {
			return '<div class="mw-playlists__art-grid mw-playlists__art-grid--empty"><span class="mw-playlists__art-placeholder" aria-hidden="true">♫</span></div>';
		}

		$cells       = '';
		$first_image = '';
		foreach ( $ids as $k => $release_id ) {
			$thumb = get_the_post_thumbnail_url( $release_id, 'thumbnail' );
			if ( 0 === $k && is_string( $thumb ) && '' !== $thumb ) {
				$first_image = $thumb;
			}
			if ( is_string( $thumb ) && '' !== $thumb ) {
				$cells .= '<span class="mw-playlists__art-cell"><img src="' . esc_url( $thumb ) . '" alt="" loading="lazy" decoding="async"></span>';
			} else {
				$initial = get_the_title( $release_id );
				$initial = is_string( $initial ) && '' !== $initial ? ( function_exists( 'mb_substr' ) ? mb_substr( $initial, 0, 1 ) : substr( $initial, 0, 1 ) ) : '♫';
				$hues    = wp_get_post_terms( $release_id, 'mw_genre', array( 'fields' => 'all' ) );
				$hue     = ( is_array( $hues ) && ! empty( $hues ) && $hues[0] instanceof \WP_Term ) ? TermHue::for_term( $hues[0] ) : 0;
				$tint    = $hue > 0 ? ' mw-term-hue-' . absint( $hue ) : '';
				$cells  .= '<span class="mw-playlists__art-cell mw-playlists__art-cell--fallback' . $tint . '"><span aria-hidden="true">' . esc_html( $initial ) . '</span></span>';
			}
		}
		// Pad to 4 cells for stable grid.
		$remaining = 4 - count( $ids );
		for ( $i = 0; $i < $remaining; $i++ ) {
			$cells .= '<span class="mw-playlists__art-cell mw-playlists__art-cell--empty" aria-hidden="true"></span>';
		}

		$back = '';
		if ( $layered && '' !== $first_image ) {
			$back = '<div class="mw-public-playlists__art-back"><img src="' . esc_url( $first_image ) . '" alt="" loading="lazy" decoding="async"></div>';
		}

		return $back . '<div class="mw-playlists__art-grid">' . $cells . '</div>';
	}

	/**
	 * One-click Play all button for a playlist.
	 */
	private function play_all_button( array $playlist, int $count ): string {
		$playlist_id = (int) $playlist['id'];
		$title       = (string) $playlist['title'];
		$share       = isset( $playlist['share_token'] ) ? (string) $playlist['share_token'] : '';
		$disabled    = 0 === $count ? ' disabled aria-disabled="true"' : '';
		$label       = sprintf(
			/* translators: %s: playlist title. */
			__( 'پخش تمام قطعه‌ها در %s', 'music-wave-core' ),
			$title
		);

		return '<button type="button" class="mw-playlists__play-all" data-mw-playlist-play data-playlist-id="' . esc_attr( (string) $playlist_id ) . '" data-mw-share="' . esc_attr( $share ) . '" aria-label="' . esc_attr( $label ) . '"' . $disabled . '>'
			. '<span class="mw-playlists__play-icon" aria-hidden="true">▶</span>'
			. '<span class="mw-playlists__play-label">' . esc_html__( 'پخش همه', 'music-wave-core' ) . '</span>'
			. '<span class="mw-playlists__play-count" aria-hidden="true">' . esc_html( (string) $count ) . '</span>'
			. '</button>';
	}

	/**
	 * Ordered items of one playlist with accessible reorder controls.
	 *
	 * @param array<string, mixed> $view Playlist view payload.
	 */
	private function items_markup( int $playlist_id, array $view ): string {
		$items = isset( $view['items'] ) && is_array( $view['items'] ) ? $view['items'] : array();
		if ( array() === $items ) {
			return '<p class="mw-playlists__empty">' . esc_html__( 'این فهرست پخش خالی است. در هر انتشار از «افزودن به فهرست پخش» استفاده کنید.', 'music-wave-core' ) . '</p>';
		}

		$total = count( $items );
		$rows  = array();
		foreach ( $items as $index => $item ) {
			$release_id = (int) $item['release_id'];
			$title      = get_the_title( $release_id );
			$title      = '' !== $title ? $title : __( 'انتشار بدون عنوان', 'music-wave-core' );
			$permalink  = get_permalink( $release_id );

			$thumb = get_the_post_thumbnail_url( $release_id, 'thumbnail' );
			$art   = is_string( $thumb ) && '' !== $thumb
				? '<img class="mw-playlists__track-art" src="' . esc_url( $thumb ) . '" alt="" loading="lazy">'
				: '<span class="mw-playlists__track-art mw-playlists__track-art--fallback" aria-hidden="true">' . esc_html( function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 1 ) : substr( $title, 0, 1 ) ) . '</span>';

			$controls = $this->track_play_button_markup( $release_id, $title, 'mw-playlists__track-play' );
			if ( $index > 0 ) {
				$controls .= $this->item_button( $playlist_id, $release_id, 'move-up', __( 'حرکت به بالا', 'music-wave-core' ), $title );
			}
			if ( $index < $total - 1 ) {
				$controls .= $this->item_button( $playlist_id, $release_id, 'move-down', __( 'حرکت به پایین', 'music-wave-core' ), $title );
			}
			$controls .= $this->item_button( $playlist_id, $release_id, 'remove-item', __( 'حذف', 'music-wave-core' ), $title );

			$rows[] = '<li class="mw-playlists__track" data-mw-playlist-track="' . esc_attr( (string) $release_id ) . '">'
				. '<span class="mw-playlists__position" aria-hidden="true">' . esc_html( (string) ( (int) $index + 1 ) ) . '</span>'
				. $art
				. '<span class="mw-playlists__track-title">'
				. ( is_string( $permalink ) && '' !== $permalink ? '<a href="' . esc_url( $permalink ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) )
				. '</span>'
				. '<span class="mw-playlists__track-actions">' . $controls . '</span>'
				. '</li>';
		}

		return '<ol class="mw-playlists__tracks">' . implode( '', $rows ) . '</ol>';
	}

	/**
	 * One item-scoped submit button carrying an accessible name.
	 */
	private function item_button( int $playlist_id, int $release_id, string $operation, string $label, string $title ): string {
		$accessible = sprintf(
			/* translators: 1: action such as Move up, 2: release title. */
			_x( '%1$s: %2$s', 'playlist item action', 'music-wave-core' ),
			$label,
			$title
		);

		return '<form class="mw-playlists__action" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-mw-playlist-action="' . esc_attr( $operation ) . '">'
			. $this->hidden_fields( $operation )
			. '<input type="hidden" name="mw_playlist_id" value="' . esc_attr( (string) $playlist_id ) . '">'
			. '<input type="hidden" name="mw_release_id" value="' . esc_attr( (string) $release_id ) . '">'
			. '<button type="submit"><span aria-hidden="true">' . esc_html( $label ) . '</span><span class="screen-reader-text">' . esc_html( $accessible ) . '</span></button>'
			. '</form>';
	}

	/**
	 * Rename/visibility/delete controls for one playlist.
	 *
	 * @param array<string, mixed> $playlist Normalized playlist.
	 */
	private function settings_form( array $playlist ): string {
		$playlist_id = (int) $playlist['id'];
		$title_id    = 'mw-playlist-title-' . $playlist_id;
		$vis_id      = 'mw-playlist-visibility-' . $playlist_id;
		$options     = '';
		foreach ( $this->repository->visibilities() as $value ) {
			$options .= '<option value="' . esc_attr( $value ) . '" ' . selected( (string) $playlist['visibility'], $value, false ) . '>' . esc_html( $this->visibility_label( $value ) ) . '</option>';
		}

		return '<form class="mw-playlists__settings" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-mw-playlist-action="update">'
			. $this->hidden_fields( 'update' )
			. '<input type="hidden" name="mw_playlist_id" value="' . esc_attr( (string) $playlist_id ) . '">'
			. '<label for="' . esc_attr( $title_id ) . '">' . esc_html__( 'نام فهرست پخش', 'music-wave-core' ) . '</label>'
			. '<input id="' . esc_attr( $title_id ) . '" type="text" name="mw_title" value="' . esc_attr( (string) $playlist['title'] ) . '" maxlength="' . esc_attr( (string) PlaylistRepository::MAX_TITLE ) . '">'
			. '<label for="' . esc_attr( $vis_id ) . '">' . esc_html__( 'چه کسی می‌تواند آن را ببیند', 'music-wave-core' ) . '</label>'
			. '<select id="' . esc_attr( $vis_id ) . '" name="mw_visibility">' . $options . '</select>'
			. '<button type="submit">' . esc_html__( 'ذخیره', 'music-wave-core' ) . '</button>'
			. '</form>'
			. '<form class="mw-playlists__delete" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-mw-playlist-action="delete">'
			. $this->hidden_fields( 'delete' )
			. '<input type="hidden" name="mw_playlist_id" value="' . esc_attr( (string) $playlist_id ) . '">'
			. '<button type="submit">'
			. '<span aria-hidden="true">' . esc_html__( 'حذف', 'music-wave-core' ) . '</span>'
			. '<span class="screen-reader-text">' . esc_html(
				sprintf(
					/* translators: %s: playlist name. */
					__( 'حذف فهرست پخش: %s', 'music-wave-core' ),
					(string) $playlist['title']
				)
			) . '</span>'
			. '</button>'
			. '</form>';
	}

	/**
	 * Share link for a shared playlist, shown to the owner only.
	 *
	 * @param array<string, mixed> $playlist Normalized playlist.
	 */
	private function share_markup( array $playlist ): string {
		$visibility = (string) ( $playlist['visibility'] ?? PlaylistRepository::VISIBILITY_PRIVATE );
		$token      = (string) ( $playlist['share_token'] ?? '' );
		$url        = $this->repository->public_url( (int) $playlist['id'], $visibility, $token );
		if ( '' === $url ) {
			return '';
		}

		return '<div class="mw-playlists__share" data-mw-share-box="' . esc_attr( (string) (int) $playlist['id'] ) . '">'
			. '<label class="mw-playlists__share-label">' . esc_html__( 'پیوند را به‌اشتراک بگذارید', 'music-wave-core' )
			. '<span class="mw-playlists__share-row">'
			. '<input type="url" readonly value="' . esc_attr( $url ) . '" spellcheck="false" data-mw-share-input>'
			. '<button type="button" class="mw-playlists__share-copy" data-mw-copy-share="' . esc_attr( $url ) . '">' . esc_html__( 'کپی پیوند', 'music-wave-core' ) . '</button>'
			. '<a class="mw-playlists__share-open" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'مشاهده صفحه ↗', 'music-wave-core' ) . '</a>'
			. '</span>'
			. '</label>'
			. '<span class="mw-playlists__share-hint">' . esc_html__( 'هر کسی که این پیوند را داشته باشد می‌تواند فهرست پخش را بدون نیاز به ورود باز و پخش کند. فهرست پخش را به حالت خصوصی برگردانید تا پیوند غیرفعال شود.', 'music-wave-core' ) . '</span>'
			. '</div>';
	}

	/**
	 * Create-playlist form.
	 */
	private function create_form(): string {
		return '<form class="mw-playlists__create" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. $this->hidden_fields( 'create' )
			. '<label for="mw-playlist-new-title">' . esc_html__( 'نام فهرست پخش جدید', 'music-wave-core' ) . '</label>'
			. '<input id="mw-playlist-new-title" type="text" name="mw_title" maxlength="' . esc_attr( (string) PlaylistRepository::MAX_TITLE ) . '" required placeholder="' . esc_attr__( 'به عنوان مثال رانندگی در اواخر شب', 'music-wave-core' ) . '">'
			. '<button type="submit">' . esc_html__( 'ایجاد فهرست پخش', 'music-wave-core' ) . '</button>'
			. '</form>';
	}

	/**
	 * Shared hidden fields for every playlist form.
	 */
	private function hidden_fields( string $operation ): string {
		return BlockSupport::hidden_action_fields( PlaylistFormHandler::NONCE, PlaylistFormHandler::ACTION, $operation );
	}

	/**
	 * Post/redirect/get notice rendered in a polite live region.
	 */
	private function notice_markup(): string {
		$notice  = isset( $_GET[ PlaylistFormHandler::NOTICE_ARG ] ) && is_scalar( $_GET[ PlaylistFormHandler::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( (string) $_GET[ PlaylistFormHandler::NOTICE_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = '' !== $notice ? $this->forms->notice_message( $notice ) : '';

		return BlockSupport::prg_notice( $message, '' !== $notice && $this->forms->notice_is_error( $notice ), 'mw-playlists__notice' );
	}

	/**
	 * Playlist expanded through the query string, when any.
	 */
	private function requested_playlist(): int {
		if ( ! isset( $_GET[ PlaylistFormHandler::CURRENT_ARG ] ) || ! is_scalar( $_GET[ PlaylistFormHandler::CURRENT_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 0;
		}
		return absint( wp_unslash( (string) $_GET[ PlaylistFormHandler::CURRENT_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Optional capability share token carried in `?mw-share=...` for unlisted links.
	 */
	private function requested_share_token(): string {
		if ( ! isset( $_GET['mw-share'] ) || ! is_scalar( $_GET['mw-share'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}
		return sanitize_text_field( wp_unslash( (string) $_GET['mw-share'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Current URL with the expanded-playlist argument set or cleared.
	 */
	private function playlist_url( int $playlist_id ): string {
		$base = remove_query_arg( array( PlaylistFormHandler::CURRENT_ARG, PlaylistFormHandler::NOTICE_ARG ), BlockSupport::current_url() );

		return $playlist_id > 0 ? add_query_arg( array( PlaylistFormHandler::CURRENT_ARG => (string) $playlist_id ), $base ) : $base;
	}

	private function visibility_label( string $visibility ): string {
		if ( PlaylistRepository::VISIBILITY_PUBLIC === $visibility ) {
			return __( 'عمومی', 'music-wave-core' );
		}
		if ( PlaylistRepository::VISIBILITY_UNLISTED === $visibility ) {
			return __( 'هر کسی پیوند', 'music-wave-core' );
		}

		return __( 'خصوصی', 'music-wave-core' );
	}
}
