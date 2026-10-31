<?php
/**
 * Artists pagination block: numbered paging for the artists index.
 *
 * Kept separate from the shelf so the Site Editor can move, restyle or drop
 * the pager independently. It reads the same {@see ArtistIndex} state as the
 * shelf, so filtered pages stay in sync.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Blocks\Concerns\ArtistsPager;
use ManaCore\MusicWave\Core\Catalog\ArtistIndex;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ArtistsPaginationBlock {
	/**
	 * Register the server-rendered artists pagination block.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		BlockSupport::register_dynamic(
			'music-wave/artists-pagination',
			array( $this, 'render' ),
			array(
				'api_version' => 3,
				'supports'    => BlockSupport::appearance_tools(),
			)
		);
	}

	/**
	 * Render the artists pager.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render( array $attributes = array() ): string {
		$per_page = BlockSupport::range_attribute( $attributes, 'perPage', 4, 48, 12 );
		$pager    = ArtistsPager::render(
			new ArtistIndex(),
			$per_page,
			'mw-artists-pagination',
			BlockSupport::bool_attribute( $attributes, 'showStatus', true )
		);

		if ( '' === $pager ) {
			return '';
		}

		return '<div ' . BlockSupport::wrapper_attributes( 'mw-artists-pagination-wrap' ) . '>' . $pager . '</div>';
	}
}
