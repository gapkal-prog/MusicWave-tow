<?php
/**
 * Account Dashboard Statistics Presenter (`music-wave/dashboard-stats`).
 *
 * Computes and renders compact, zero-N+1 user statistics (entitled downloads,
 * saved releases, followed artists) for both the standalone `music-wave/dashboard-stats`
 * block and the inline dashboard stats bars rendered inside `account-dashboard`.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Account;

use ManaCore\MusicWave\Core\Blocks\BlockSupport;
use ManaCore\MusicWave\Core\Library\LibraryRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StatsPresenter {
	/** @var LibraryRepository|null */
	private $library;

	public function __construct( ?LibraryRepository $library = null ) {
		$this->library = $library;
	}

	/**
	 * Render standalone `music-wave/dashboard-stats` block.
	 *
	 * @param array<string, mixed> $attributes     Block attributes.
	 * @param callable             $entitled_count Callback returning entitled release count for a user ID.
	 */
	public function render_block( array $attributes, callable $entitled_count ): string {
		$compact       = BlockSupport::bool_attribute( $attributes, 'compact', true );
		$show_entitled = BlockSupport::bool_attribute( $attributes, 'showEntitled', true );
		$show_saved    = BlockSupport::bool_attribute( $attributes, 'showSaved', true );
		$show_artists  = BlockSupport::bool_attribute( $attributes, 'showArtists', true );

		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			if ( BlockSupport::is_editor_context() ) {
				return $this->preview_markup( $compact, $show_entitled, $show_saved, $show_artists );
			}
			return '';
		}

		$list = $this->render_list(
			$user_id,
			$entitled_count,
			array(
				'compact'       => $compact,
				'show_entitled' => $show_entitled,
				'show_saved'    => $show_saved,
				'show_artists'  => $show_artists,
			)
		);
		if ( '' === $list ) {
			return BlockSupport::is_editor_context()
				? $this->preview_markup( $compact, true, true, true )
				: '';
		}

		return '<section ' . BlockSupport::wrapper_attributes( 'mw-dashboard-stats' ) . '>' . $list . '</section>';
	}

	/**
	 * Render `<ul class="mw-user-dashboard__stats">` matching `account-library.css`.
	 *
	 * @param array<string, bool> $options Display flags.
	 */
	public function render_list( int $user_id, callable $entitled_count, array $options = array() ): string {
		if ( $user_id < 1 ) {
			return '';
		}

		$compact       = ! isset( $options['compact'] ) || ! empty( $options['compact'] );
		$show_entitled = ! isset( $options['show_entitled'] ) || ! empty( $options['show_entitled'] );
		$show_saved    = ! isset( $options['show_saved'] ) || ! empty( $options['show_saved'] );
		$show_artists  = ! isset( $options['show_artists'] ) || ! empty( $options['show_artists'] );

		$counts = $this->library_counts( $user_id );
		$stats  = $this->build_items(
			$show_entitled ? (int) $entitled_count( $user_id ) : null,
			$show_saved ? $counts['releases'] : null,
			$show_artists ? $counts['artists'] : null
		);

		return $this->items_markup( $stats, $compact );
	}

	/**
	 * Render non-empty preview stats inside the Site Editor when signed out or all counters disabled.
	 */
	private function preview_markup( bool $compact, bool $show_entitled, bool $show_saved, bool $show_artists ): string {
		$stats = $this->build_items(
			$show_entitled ? 0 : null,
			$show_saved ? 0 : null,
			$show_artists ? 0 : null
		);
		if ( empty( $stats ) ) {
			$stats = $this->build_items( 0, 0, 0 );
		}

		return '<section ' . BlockSupport::wrapper_attributes( 'mw-dashboard-stats' ) . '>' . $this->items_markup( $stats, $compact ) . '</section>';
	}

	/**
	 * @return array<int, array{value: int, label: string}>
	 */
	private function build_items( ?int $entitled, ?int $saved, ?int $artists ): array {
		$stats = array();
		if ( null !== $entitled ) {
			$stats[] = array(
				'value' => $entitled,
				'label' => __( 'دانلودهای موجود', 'music-wave-core' ),
			);
		}
		if ( null !== $saved ) {
			$stats[] = array(
				'value' => $saved,
				'label' => __( 'علاقه‌مندی‌ها', 'music-wave-core' ),
			);
		}
		if ( null !== $artists ) {
			$stats[] = array(
				'value' => $artists,
				'label' => __( 'هنرمندان دنبال‌شده', 'music-wave-core' ),
			);
		}
		return $stats;
	}

	/**
	 * @param array<int, array{value: int, label: string}> $stats   Counter items.
	 * @param bool                                        $compact Compact pill layout.
	 */
	private function items_markup( array $stats, bool $compact ): string {
		if ( empty( $stats ) ) {
			return '';
		}
		$list_class = 'mw-user-dashboard__stats' . ( $compact ? ' mw-user-dashboard__stats--compact' : '' );

		ob_start();
		?>
		<ul class="<?php echo esc_attr( $list_class ); ?>">
			<?php foreach ( $stats as $stat ) : ?>
				<li>
					<strong><?php echo esc_html( function_exists( 'number_format_i18n' ) ? number_format_i18n( $stat['value'] ) : (string) $stat['value'] ); ?></strong>
					<span><?php echo esc_html( $stat['label'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		return trim( (string) ob_get_clean() );
	}

	/**
	 * Count saved releases and followed artists in a single batched pass.
	 *
	 * @return array{releases: int, artists: int}
	 */
	private function library_counts( int $user_id ): array {
		if ( null === $this->library ) {
			return array(
				'releases' => 0,
				'artists'  => 0,
			);
		}

		$entries  = $this->library->all( $user_id );
		$releases = 0;
		$artists  = 0;
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['type'] ) ) {
				continue;
			}
			if ( LibraryRepository::TYPE_ARTIST === $entry['type'] ) {
				++$artists;
			} elseif ( in_array( $entry['type'], $this->library->release_types(), true ) ) {
				++$releases;
			}
		}

		return array(
			'releases' => $releases,
			'artists'  => $artists,
		);
	}
}
