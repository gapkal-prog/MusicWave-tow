<?php
/**
 * Continue-listening block: consent-gated recently played & resume surface.
 *
 * Three viewer states render server-side with zero JavaScript required:
 * guests see a sign-in prompt (progressively enhanced with local session
 * resume items in the browser), opted-out listeners see a one-click consent
 * panel, and consenting listeners see their in-progress / recent releases
 * rendered with the shared MusicWave release-shelf card system, exact
 * resume timestamps, and per-item / full-list clear actions.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Listening\ListeningRepository;
use ManaCore\MusicWave\Core\Account\AuthPolicy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ListeningBlocks {
	/** @var ListeningRepository */
	private $repository;

	/** @var array<string, string> */
	private $last_rendered_state_by_source = array();

	public function __construct( ListeningRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register the block and its progressive-enhancement assets.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		BlockSupport::register_dynamic(
			'music-wave/continue-listening',
			function ( $attributes ): string {
				return $this->render( is_array( $attributes ) ? $attributes : array() );
			},
			array(
				'api_version' => 3,
				'supports'    => BlockSupport::appearance_tools(),
			)
		);

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the progressive enhancement runtime on pages containing the block.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( is_admin() || ! \ManaCore\MusicWave\Core\Support\Settings::listening_enabled() ) {
			return;
		}

		if ( ! function_exists( 'has_block' ) || ! has_block( 'music-wave/continue-listening' ) ) {
			return;
		}

		$this->enqueue_runtime();
	}

	private function enqueue_runtime(): void {
		if ( is_admin() || BlockSupport::is_editor_context() ) {
			return;
		}
		$user_id = get_current_user_id();
		wp_enqueue_script(
			'music-wave-listening',
			MUSIC_WAVE_CORE_URL . 'assets/listening.js',
			array( 'wp-api-fetch' ),
			MUSIC_WAVE_CORE_VERSION,
			true
		);
		wp_localize_script(
			'music-wave-listening',
			'musicWaveListening',
			array(
				'restUrl'    => esc_url_raw( rest_url() ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'loggedIn'   => $user_id > 0,
				'hasConsent' => $user_id > 0 && $this->repository->has_consent( $user_id ),
				'labels'     => array(
					'saving'         => __( 'فعال کردن سابقهٔ گوش‌دادن…', 'music-wave-core' ),
					'disabling'      => __( 'در حال غیرفعال کردن سابقهٔ گوش‌دادن…', 'music-wave-core' ),
					'enabled'        => __( 'سابقهٔ گوش‌دادن روشن است. برای پرکردن این ریل، چیزی پخش کنید.', 'music-wave-core' ),
					'consentMessage' => __( 'سابقهٔ گوش‌دادن را روشن کنید تا انتشارهای اخیراً پخش‌شده‌تان را اینجا ببینید. شما در کنترل باقی می‌مانید - سابقهٔ خصوصی است و در هر زمان می‌توان آن را پاک کرد.', 'music-wave-core' ),
					'consentLabel'   => __( 'تاریخچه گوش‌دادن را روشن کنید', 'music-wave-core' ),
					'error'          => __( 'سابقهٔ گوش‌دادن را نمی‌توان فعال کرد. دوباره امتحان کنید.', 'music-wave-core' ),
					'disableError'   => __( 'غیرفعال کردن سابقهٔ گوش‌دادن انجام نشد. دوباره امتحان کنید.', 'music-wave-core' ),
					'clear'          => __( 'پاک‌سازی سابقه', 'music-wave-core' ),
					'disable'        => __( 'غیرفعال‌سازی سابقه', 'music-wave-core' ),
					'cleared'        => __( 'سابقهٔ پخش پاک شد.', 'music-wave-core' ),
					'removeItem'     => __( 'حذف از فهرست ادامهٔ پخش', 'music-wave-core' ),
					/* translators: %s: formatted timestamp like 1:42. */
					'resumeFrom'     => __( 'ادامه از %s', 'music-wave-core' ),
					/* translators: %s: release title. */
					'playTitle'      => __( 'پخش %s', 'music-wave-core' ),
					/* translators: %s: release title. */
					'openTitle'      => __( 'باز کردن %s', 'music-wave-core' ),
					'justNow'        => __( 'همین الان', 'music-wave-core' ),
					'untitled'       => __( 'انتشار بدون عنوان', 'music-wave-core' ),
				),
			)
		);
	}

	/**
	 * Render the continue-listening surface for the current viewer.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes ): string {
		if ( ! \ManaCore\MusicWave\Core\Support\Settings::listening_enabled() ) {
			if ( BlockSupport::is_editor_context() ) {
				return '<section ' . BlockSupport::wrapper_attributes( 'mw-continue-listening mw-continue-listening--empty' ) . '>'
					. '<p class="mw-continue-listening__empty">' . esc_html__( 'تاریخچه گوش‌دادن در تنظیمات MusicWave غیرفعال شده است.', 'music-wave-core' ) . '</p>'
					. '</section>';
			}
			return '';
		}

		$this->enqueue_runtime();
		$user_id     = get_current_user_id();
		$has_consent = $user_id > 0 && $this->repository->has_consent( $user_id );
		$source      = BlockSupport::key_attribute( $attributes, 'source', array( 'continue', 'played' ), 'continue' );
		$event       = 'played' === $source ? ListeningRepository::EVENT_PLAYED : ListeningRepository::EVENT_PROGRESS;
		$history_sig = '';
		if ( $has_consent ) {
			$rows        = $this->repository->recent( $user_id, $event, 24 );
			$history_sig = md5( (string) wp_json_encode( $rows ) );
		}

		$is_rest = ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() );
		if ( ! BlockSupport::is_editor_context() && ! $is_rest ) {
			$state_key = $user_id . ':' . ( $has_consent ? '1' : '0' ) . ':' . $history_sig;
			if ( ( $this->last_rendered_state_by_source[ $source ] ?? null ) === $state_key ) {
				return '';
			}
			$this->last_rendered_state_by_source[ $source ] = $state_key;
		}

		if ( $user_id < 1 ) {
			return $this->render_guest( $attributes );
		}

		if ( ! $has_consent ) {
			return $this->render_consent( $attributes );
		}

		return $this->render_history( $attributes, $user_id );
	}

	/**
	 * Render one interactive continue-listening card for SSR and REST refreshes.
	 *
	 * @param int                  $release_id  Readable release.
	 * @param int                  $updated_at  Last activity timestamp.
	 * @param array<string, mixed> $options     Resolved display options.
	 * @param int                  $position    Saved playback position in seconds.
	 * @param bool                 $dismissible Whether to attach dismiss affordance.
	 */
	public function render_item_card( int $release_id, int $updated_at, array $options, int $position = 0, bool $dismissible = false ): string {
		return $this->history_card( $release_id, $updated_at, $options, $position, $dismissible );
	}

	/**
	 * Shared data-* attributes stamped on `<section>` so `listening.js` can
	 * refresh or hydrate any state using the block's exact editor settings.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function section_config_attributes( array $attributes ): string {
		$source        = BlockSupport::key_attribute( $attributes, 'source', array( 'continue', 'played' ), 'continue' );
		$layout        = BlockSupport::key_attribute( $attributes, 'layout', array( 'grid', 'scroll', 'list' ), 'scroll' );
		$columns       = BlockSupport::range_attribute( $attributes, 'columns', 2, 6, 4 );
		$limit         = BlockSupport::range_attribute( $attributes, 'itemsToShow', 2, 24, 8 );
		$shape         = BlockSupport::key_attribute( $attributes, 'imageShape', array( 'square', 'circle', 'landscape', 'portrait' ), 'square' );
		$heading       = BlockSupport::optional_text_attribute( $attributes, 'heading', $this->default_heading( $attributes ), 'showHeading' );
		$intro         = isset( $attributes['intro'] ) && is_scalar( $attributes['intro'] ) ? sanitize_text_field( (string) $attributes['intro'] ) : '';
		$empty         = BlockSupport::text_attribute( $attributes, 'emptyMessage', $this->default_empty_message( $attributes ) );
		$consent_msg   = BlockSupport::text_attribute( $attributes, 'consentMessage', __( 'سابقهٔ گوش‌دادن را روشن کنید تا انتشارهای اخیراً پخش‌شده‌تان را اینجا ببینید. شما در کنترل باقی می‌مانید - سابقهٔ خصوصی است و در هر زمان می‌توان آن را پاک کرد.', 'music-wave-core' ) );
		$consent_label = BlockSupport::text_attribute( $attributes, 'consentButtonLabel', __( 'تاریخچه گوش‌دادن را روشن کنید', 'music-wave-core' ) );
		$section_url   = isset( $attributes['sectionUrl'] ) && is_scalar( $attributes['sectionUrl'] ) ? esc_url_raw( (string) $attributes['sectionUrl'] ) : '';
		$section_label = BlockSupport::text_attribute( $attributes, 'sectionLinkLabel', __( 'همه را ببینید', 'music-wave-core' ) );
		$show_heading  = '' !== $heading;
		$show_artwork  = ! isset( $attributes['showArtwork'] ) || false !== $attributes['showArtwork'];
		$show_artist   = ! isset( $attributes['showArtist'] ) || false !== $attributes['showArtist'];
		$show_when     = ! isset( $attributes['showWhen'] ) || false !== $attributes['showWhen'];
		$show_preview  = ! isset( $attributes['showPreview'] ) || false !== $attributes['showPreview'];

		return ' data-mw-listening-source="' . esc_attr( $source ) . '"'
			. ' data-mw-listening-layout="' . esc_attr( $layout ) . '"'
			. ' data-mw-listening-columns="' . esc_attr( (string) $columns ) . '"'
			. ' data-mw-listening-limit="' . esc_attr( (string) $limit ) . '"'
			. ' data-mw-listening-shape="' . esc_attr( $shape ) . '"'
			. ' data-mw-listening-heading="' . esc_attr( $heading ) . '"'
			. ' data-mw-listening-intro="' . esc_attr( $intro ) . '"'
			. ' data-mw-listening-empty="' . esc_attr( $empty ) . '"'
			. ' data-mw-optin-message="' . esc_attr( $consent_msg ) . '"'
			. ' data-mw-optin-label="' . esc_attr( $consent_label ) . '"'
			. ' data-mw-listening-more-url="' . esc_attr( $section_url ) . '"'
			. ' data-mw-listening-more-label="' . esc_attr( $section_label ) . '"'
			. ' data-mw-listening-show-heading="' . ( $show_heading ? '1' : '0' ) . '"'
			. ' data-mw-listening-show-artwork="' . ( $show_artwork ? '1' : '0' ) . '"'
			. ' data-mw-listening-show-artist="' . ( $show_artist ? '1' : '0' ) . '"'
			. ' data-mw-listening-show-when="' . ( $show_when ? '1' : '0' ) . '"'
			. ' data-mw-listening-show-preview="' . ( $show_preview ? '1' : '0' ) . '"';
	}

	/**
	 * Guest state: a compact sign-in prompt keeps the layout reserved, while
	 * `listening.js` progressively hydrates local device playback items above it.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_guest( array $attributes ): string {
		$message = BlockSupport::text_attribute( $attributes, 'guestMessage', __( 'برای پیگیری چیزهایی که پخش می‌کنید، وارد سیستم شوید و از همان‌جایی که متوقف کرده‌اید ادامه دهید.', 'music-wave-core' ) );

		return '<section ' . BlockSupport::wrapper_attributes( 'mw-continue-listening mw-continue-listening--guest' ) . $this->section_config_attributes( $attributes ) . '>'
			. '<div class="mw-continue-listening__guest-rail" data-mw-listening-guest-rail hidden></div>'
			. '<div class="mw-continue-listening__panel"><span class="mw-continue-listening__panel-icon" aria-hidden="true">▶</span>'
			. '<p class="mw-continue-listening__panel-text">' . esc_html( $message ) . '</p>'
			. '<a class="wp-element-button mw-continue-listening__panel-cta" href="' . esc_url( AuthPolicy::login_url( BlockSupport::current_url() ) ) . '">' . esc_html__( 'وارد شوید', 'music-wave-core' ) . '</a></div>'
			. '</section>';
	}

	/**
	 * Opt-in state: explain the feature and offer one-click consent.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_consent( array $attributes ): string {
		$this->enqueue_runtime();
		$message = BlockSupport::text_attribute( $attributes, 'consentMessage', __( 'سابقهٔ گوش‌دادن را روشن کنید تا انتشارهای اخیراً پخش‌شده‌تان را اینجا ببینید. شما در کنترل باقی می‌مانید - سابقهٔ خصوصی است و در هر زمان می‌توان آن را پاک کرد.', 'music-wave-core' ) );
		$label   = BlockSupport::text_attribute( $attributes, 'consentButtonLabel', __( 'تاریخچه گوش‌دادن را روشن کنید', 'music-wave-core' ) );

		return '<section ' . BlockSupport::wrapper_attributes( 'mw-continue-listening mw-continue-listening--consent' ) . $this->section_config_attributes( $attributes ) . '>'
			. '<div class="mw-continue-listening__panel"><span class="mw-continue-listening__panel-icon" aria-hidden="true">◎</span>'
			. '<p class="mw-continue-listening__panel-text">' . esc_html( $message ) . '</p>'
			. '<button type="button" class="wp-element-button mw-continue-listening__panel-cta" data-mw-listening-consent>' . esc_html( $label ) . '</button>'
			. '<p class="mw-continue-listening__status" role="status" aria-live="polite" data-mw-listening-status hidden></p></div>'
			. '</section>';
	}

	/**
	 * History state: header plus a release-shelf rail of recent items.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param int                  $user_id    Consenting viewer.
	 */
	private function render_history( array $attributes, int $user_id ): string {
		$heading = BlockSupport::optional_text_attribute( $attributes, 'heading', $this->default_heading( $attributes ), 'showHeading' );
		$intro   = isset( $attributes['intro'] ) && is_scalar( $attributes['intro'] ) ? sanitize_text_field( (string) $attributes['intro'] ) : '';
		$source  = BlockSupport::key_attribute( $attributes, 'source', array( 'continue', 'played' ), 'continue' );
		$event   = 'played' === $source
			? ListeningRepository::EVENT_PLAYED
			: ListeningRepository::EVENT_PROGRESS;

		$items = $this->repository->recent( $user_id, $event, BlockSupport::range_attribute( $attributes, 'itemsToShow', 2, 24, 8 ) );

		if ( array() === $items ) {
			return $this->empty_state_markup( $attributes );
		}

		$options = array(
			'layout'       => BlockSupport::key_attribute( $attributes, 'layout', array( 'grid', 'scroll', 'list' ), 'scroll' ),
			'columns'      => BlockSupport::range_attribute( $attributes, 'columns', 2, 6, 4 ),
			'shape'        => BlockSupport::key_attribute( $attributes, 'imageShape', array( 'square', 'circle', 'landscape', 'portrait' ), 'square' ),
			'show_artwork' => ! isset( $attributes['showArtwork'] ) || false !== $attributes['showArtwork'],
			'show_artist'  => ! isset( $attributes['showArtist'] ) || false !== $attributes['showArtist'],
			'show_when'    => ! isset( $attributes['showWhen'] ) || false !== $attributes['showWhen'],
			'show_preview' => ! isset( $attributes['showPreview'] ) || false !== $attributes['showPreview'],
		);

		$cards = array();
		foreach ( $items as $item ) {
			$position = 'played' === $source ? 0 : (int) $item['position'];
			$card     = $this->history_card( (int) $item['release_id'], (int) $item['updated_at'], $options, $position, true );
			if ( '' !== $card ) {
				$cards[] = $card;
			}
		}

		if ( array() === $cards ) {
			return $this->empty_state_markup( $attributes );
		}

		$header = $this->render_header( $attributes, $heading, $intro, true, true );

		// The items wrapper reuses the theme's release-shelf chrome so grid,
		// scroll, and list rails match every other shelf on the site.
		$shelf_class = 'mw-release-shelf mw-release-shelf--' . $options['layout'];
		if ( 'grid' === $options['layout'] ) {
			$shelf_class .= ' mw-release-shelf--columns-' . $options['columns'];
		}

		return '<section ' . BlockSupport::wrapper_attributes( 'mw-continue-listening' ) . $this->section_config_attributes( $attributes ) . '>'
			. $header
			. '<div class="' . esc_attr( $shelf_class ) . '">'
			. \ManaCore\MusicWave\Core\Support\ShelfNavMarkup::items_region(
				(string) $options['layout'],
				implode( '', $cards ),
				$heading,
				'data-mw-listening-items'
			)
			. '</div>'
			. '</section>';
	}

	/**
	 * Empty shelf state for an opted-in listener, retaining the turn-off control.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function empty_state_markup( array $attributes ): string {
		$empty = BlockSupport::text_attribute( $attributes, 'emptyMessage', $this->default_empty_message( $attributes ) );

		return '<section ' . BlockSupport::wrapper_attributes( 'mw-continue-listening mw-continue-listening--empty' ) . $this->section_config_attributes( $attributes ) . '>'
			. '<div class="mw-continue-listening__empty-row">'
			. '<p class="mw-continue-listening__empty">' . esc_html( $empty ) . '</p>'
			. '<button type="button" class="mw-continue-listening__disable" data-mw-listening-disable>' . esc_html__( 'غیرفعال‌سازی سابقه', 'music-wave-core' ) . '</button>'
			. '</div>'
			. '<p class="mw-continue-listening__status" role="status" aria-live="polite" data-mw-listening-status hidden></p>'
			. '</section>';
	}

	/**
	 * Build header markup for the history state.
	 *
	 * @param array<string, mixed> $attributes   Block attributes.
	 * @param string               $heading      Section heading.
	 * @param string               $intro        Optional intro text.
	 * @param bool                 $show_clear   Include clear-history button.
	 * @param bool                 $show_disable Include turn-off-history button.
	 */
	private function render_header( array $attributes, string $heading, string $intro, bool $show_clear, bool $show_disable = false ): string {
		if ( isset( $attributes['showHeading'] ) && false === $attributes['showHeading'] ) {
			return '';
		}

		$section_url = isset( $attributes['sectionUrl'] ) && is_scalar( $attributes['sectionUrl'] ) ? esc_url_raw( (string) $attributes['sectionUrl'] ) : '';
		$actions     = array();
		if ( $show_clear ) {
			$actions[] = '<button type="button" class="mw-continue-listening__clear" data-mw-listening-clear>' . esc_html__( 'پاک‌سازی سابقه', 'music-wave-core' ) . '</button>';
		}
		if ( $show_disable ) {
			$actions[] = '<button type="button" class="mw-continue-listening__disable" data-mw-listening-disable>' . esc_html__( 'غیرفعال‌سازی سابقه', 'music-wave-core' ) . '</button>';
		}
		if ( '' !== $section_url ) {
			$label     = BlockSupport::text_attribute( $attributes, 'sectionLinkLabel', __( 'همه را ببینید', 'music-wave-core' ) );
			$actions[] = '<a class="mw-continue-listening__more" href="' . esc_url( $section_url ) . '">' . esc_html( $label ) . '<span aria-hidden="true">&rarr;</span></a>';
		}
		$actions_html = array() !== $actions ? '<div class="mw-continue-listening__actions">' . implode( '', $actions ) . '</div>' : '';

		return '<header class="mw-continue-listening__header"><div><h2>' . esc_html( $heading ) . '</h2>'
			. ( '' !== $intro ? '<p class="mw-continue-listening__intro">' . esc_html( $intro ) . '</p>' : '' )
			. '</div>' . $actions_html . '</header>';
	}

	/**
	 * One history card using the shared release-card markup, extended with the
	 * "played ago" meta line and optional exact resume timestamp / dismiss action.
	 *
	 * @param int                  $release_id  Readable release.
	 * @param int                  $updated_at  Last activity timestamp.
	 * @param array<string, mixed> $options     Resolved display options.
	 * @param int                  $position    Saved playback position in seconds.
	 * @param bool                 $dismissible Whether to append item remove button.
	 */
	private function history_card( int $release_id, int $updated_at, array $options, int $position = 0, bool $dismissible = false ): string {
		$data = ReleaseCard::presentation_data( $release_id );
		if ( array() === $data ) {
			return '';
		}

		// An untitled release still needs a readable card, and the artwork
		// placeholder takes its initial from the same resolved title.
		if ( '' === $data['title'] ) {
			$data['title']   = __( 'انتشار بدون عنوان', 'music-wave-core' );
			$data['initial'] = ReleaseCard::initial( $data['title'] );
		}

		$overlay = '';
		if ( $options['show_preview'] ) {
			$filtered = apply_filters( 'music_wave_card_play_button', '', $release_id, 'mw-release-shelf__play' );
			$overlay  = is_string( $filtered ) ? $filtered : '';
			if ( '' !== $overlay && $position > 0 ) {
				$overlay = (string) preg_replace(
					'/<button\b/',
					'<button data-mw-resume-position="' . esc_attr( (string) $position ) . '"',
					$overlay,
					1
				);
			}
		}

		if ( $dismissible && $options['show_artwork'] ) {
			$overlay .= '<button type="button" class="mw-continue-listening__dismiss" data-mw-listening-remove="' . esc_attr( (string) $release_id ) . '" aria-label="' . esc_attr__( 'حذف از فهرست ادامهٔ پخش', 'music-wave-core' ) . '">&times;</button>';
		}

		$when = '';
		if ( $options['show_when'] && $updated_at > 0 ) {
			$when = '<time datetime="' . esc_attr( gmdate( 'c', $updated_at ) ) . '">' . esc_html( BlockSupport::relative_time_label( $updated_at ) ) . '</time>';
		}

		$body_html = '';
		if ( $position > 0 ) {
			$formatted = $this->format_seconds( $position );
			/* translators: %s: formatted timestamp like 1:42. */
			$resume_text = sprintf( __( 'ادامه از %s', 'music-wave-core' ), $formatted );
			$body_html   = '<div class="mw-continue-listening__resume" data-mw-resume-seconds="' . esc_attr( (string) $position ) . '">'
				. '<span class="mw-continue-listening__bar" aria-hidden="true"><i data-mw-progress-fill></i></span>'
				. '<span class="mw-continue-listening__stamp">' . esc_html( $resume_text ) . '</span>'
				. '</div>';
		}

		return ReleaseCard::render(
			$data,
			array(
				'shape'              => (string) $options['shape'],
				'image_attributes'   => array(
					'loading'       => 'lazy',
					'fetchpriority' => 'low',
					'decoding'      => 'async',
				),
				/* translators: %s: music release title. */
				'open_label'         => sprintf( __( 'باز کردن %s', 'music-wave-core' ), $data['title'] ),
				'overlay'            => $overlay,
				'show_artwork'       => $options['show_artwork'],
				'show_artist'        => $options['show_artist'],
				'extra_item_classes' => 'mw-continue-listening__item',
				'meta_html'          => $when,
				'body_html'          => $body_html,
			)
		);
	}

	private function format_seconds( int $seconds ): string {
		$seconds = max( 0, $seconds );
		$hours   = intdiv( $seconds, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );
		$remain  = $seconds % 60;
		if ( $hours > 0 ) {
			return sprintf( '%d:%02d:%02d', $hours, $minutes, $remain );
		}
		return sprintf( '%d:%02d', $minutes, $remain );
	}

	/**
	 * State-aware translated heading.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function default_heading( array $attributes ): string {
		return 'played' === BlockSupport::key_attribute( $attributes, 'source', array( 'continue', 'played' ), 'continue' )
			? __( 'اخیراً پخش‌شده', 'music-wave-core' )
			: __( 'به گوش‌دادن ادامه دهید', 'music-wave-core' );
	}

	/**
	 * State-aware translated empty message.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function default_empty_message( array $attributes ): string {
		return 'played' === BlockSupport::key_attribute( $attributes, 'source', array( 'continue', 'played' ), 'continue' )
			? __( 'هنوز چیزی پخش نشده است. پخش را در هر انتشاری فشار دهید و اینجا نشان داده می‌شود.', 'music-wave-core' )
			: __( 'هیچ چیزی در حال پیشرفت نیست هر انتشاری را شروع کنید و بعداً آن را در اینجا از سر بگیرید.', 'music-wave-core' );
	}
}
