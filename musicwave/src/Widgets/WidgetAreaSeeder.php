<?php
declare(strict_types=1);

namespace ManaCore\MusicWave\Theme\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds default MusicWave block widgets into WordPress widget sidebars once.
 *
 * Pre-populates `musicwave-sidebar` (`نوار کناری` in Appearance -> Widgets) with
 * MusicWave sidebar blocks (`music-wave/continue-listening` and
 * `music-wave/access-panel`) once per installation so administrators can
 * freely reorder, delete, or re-add them at any time without deleted widgets
 * ever reappearing or rendering twice.
 */
final class WidgetAreaSeeder {

	public const SEEDED_OPTION   = 'musicwave_default_widgets_seeded';
	public const PRIMARY_SIDEBAR = 'musicwave-sidebar';

	/**
	 * Ordered default block widgets placed in `musicwave-sidebar` on initial setup.
	 *
	 * @var array<string, string>
	 */
	public const DEFAULT_SIDEBAR_BLOCKS = array(
		'music-wave/continue-listening' => '<!-- wp:music-wave/continue-listening /-->',
		'music-wave/access-panel'       => '<!-- wp:music-wave/access-panel /-->',
	);

	/**
	 * Register WordPress widget-seeding lifecycle hooks.
	 */
	public function register(): void {
		add_action( 'after_switch_theme', array( $this, 'seed_once' ) );
		add_action( 'widgets_init', array( $this, 'seed_once' ), 20 );
	}

	/**
	 * Populate default block widgets into `musicwave-sidebar` strictly once.
	 *
	 * Once `SEEDED_OPTION` is set, administrator deletions in Appearance ->
	 * Widgets are permanently respected, while manual re-additions continue
	 * to render natively through WordPress's widget storage.
	 */
	public function seed_once(): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		if ( (bool) get_option( self::SEEDED_OPTION, false ) ) {
			return;
		}

		$raw_sidebars     = get_option( 'sidebars_widgets', array() );
		$sidebars_widgets = is_array( $raw_sidebars ) ? $raw_sidebars : array();

		$raw_blocks    = get_option( 'widget_block', array() );
		$widget_blocks = is_array( $raw_blocks ) ? $raw_blocks : array();

		if ( ! isset( $sidebars_widgets[ self::PRIMARY_SIDEBAR ] ) || ! is_array( $sidebars_widgets[ self::PRIMARY_SIDEBAR ] ) ) {
			$sidebars_widgets[ self::PRIMARY_SIDEBAR ] = array();
		}

		$existing_block_names = $this->collect_registered_block_names( $sidebars_widgets, $widget_blocks );
		$next_index           = $this->next_widget_index( $widget_blocks );
		$modified             = false;

		foreach ( self::DEFAULT_SIDEBAR_BLOCKS as $block_name => $serialized_markup ) {
			if ( isset( $existing_block_names[ $block_name ] ) ) {
				continue;
			}

			$widget_blocks[ $next_index ]                = array( 'content' => $serialized_markup );
			$sidebars_widgets[ self::PRIMARY_SIDEBAR ][] = 'block-' . $next_index;
			$existing_block_names[ $block_name ]         = true;
			++$next_index;
			$modified = true;
		}

		if ( $modified ) {
			if ( ! isset( $widget_blocks['_multiwidget'] ) ) {
				$widget_blocks['_multiwidget'] = 1;
			}
			update_option( 'widget_block', $widget_blocks );
			update_option( 'sidebars_widgets', $sidebars_widgets );
		}

		update_option( self::SEEDED_OPTION, '1', true );
	}

	/**
	 * Inspect active and inactive widget slots so already-present blocks are never duplicated.
	 *
	 * @param array<string, mixed>     $sidebars_widgets WordPress `sidebars_widgets` map.
	 * @param array<int|string, mixed> $widget_blocks    WordPress `widget_block` instances.
	 * @return array<string, bool>
	 */
	private function collect_registered_block_names( array $sidebars_widgets, array $widget_blocks ): array {
		$found   = array();
		$buckets = array(
			self::PRIMARY_SIDEBAR,
			'wp_inactive_widgets',
		);

		foreach ( $buckets as $bucket ) {
			if ( empty( $sidebars_widgets[ $bucket ] ) || ! is_array( $sidebars_widgets[ $bucket ] ) ) {
				continue;
			}

			foreach ( $sidebars_widgets[ $bucket ] as $widget_id ) {
				if ( ! is_string( $widget_id ) || 0 !== strpos( $widget_id, 'block-' ) ) {
					continue;
				}
				$number = (int) substr( $widget_id, 6 );
				if ( $number <= 0 || ! isset( $widget_blocks[ $number ] ) || ! is_array( $widget_blocks[ $number ] ) ) {
					continue;
				}
				$content = isset( $widget_blocks[ $number ]['content'] ) && is_string( $widget_blocks[ $number ]['content'] )
					? $widget_blocks[ $number ]['content']
					: '';
				foreach ( array_keys( self::DEFAULT_SIDEBAR_BLOCKS ) as $target_block ) {
					if ( false !== strpos( $content, 'wp:' . $target_block ) ) {
						$found[ $target_block ] = true;
					}
				}
			}
		}

		return $found;
	}

	/**
	 * Compute the next positive integer slot index for `widget_block` (minimum 2 per WP Core convention).
	 *
	 * @param array<int|string, mixed> $widget_blocks WordPress `widget_block` instances.
	 */
	private function next_widget_index( array $widget_blocks ): int {
		$max = 1;
		foreach ( array_keys( $widget_blocks ) as $key ) {
			if ( is_int( $key ) || ctype_digit( $key ) ) {
				$numeric = (int) $key;
				if ( $numeric > $max ) {
					$max = $numeric;
				}
			}
		}

		return $max + 1;
	}
}
