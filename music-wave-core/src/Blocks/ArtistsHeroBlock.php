<?php
/**
 * Artists hero block: the editorial banner that opens the artists index.
 *
 * Renders an eyebrow, title, description and catalogue stats. It is a
 * standalone block so the Site Editor can restyle, move, duplicate or remove
 * the banner without touching the shelf, toolbar, alphabet or pagination below
 * it.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Catalog\ArtistIndex;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ArtistsHeroBlock {
	/**
	 * Register the server-rendered artists hero block.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		BlockSupport::register_dynamic(
			'music-wave/artists-hero',
			array( $this, 'render' ),
			array(
				'api_version' => 3,
				'supports'    => BlockSupport::appearance_tools(),
			)
		);
	}

	/**
	 * Render the artists hero banner.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render( array $attributes = array() ): string {
		$index = new ArtistIndex();

		if ( array() === $index->terms() && BlockSupport::is_editor_context() ) {
			return BlockSupport::editor_placeholder(
				__( 'بنر هنرمندان', 'music-wave-core' ),
				__( 'هنوز خواننده‌ای با انتشار منتشرشده وجود ندارد. با افزودن هنرمند به انتشارها، آمار این بنر خودکار پر می‌شود.', 'music-wave-core' ),
				'mw-artists-hero mw-artists-hero--placeholder',
				'dashicons-admin-users'
			);
		}

		$eyebrow     = BlockSupport::optional_text_attribute( $attributes, 'eyebrow', __( 'کتابخانهٔ هنرمندان', 'music-wave-core' ) );
		$heading     = BlockSupport::optional_text_attribute( $attributes, 'heading', __( 'خوانندگان', 'music-wave-core' ) );
		$description = BlockSupport::optional_text_attribute(
			$attributes,
			'description',
			__( 'همهٔ خوانندگان کاتالوگ را در یک جا ببینید؛ بر اساس محبوبیت یا الفبا مرتب کنید و هر هنرمند را دنبال کنید تا انتشارهای تازه‌اش را از دست ندهید.', 'music-wave-core' )
		);
		// Heading level arrives from a select, so it may be a numeric string.
		$level = isset( $attributes['level'] ) && is_numeric( $attributes['level'] )
			? min( 6, max( 1, (int) $attributes['level'] ) )
			: 1;

		return '<section ' . BlockSupport::wrapper_attributes( 'mw-artists-hero' ) . '>'
			. '<div class="mw-artists-hero__content">'
			. ( '' !== $eyebrow ? '<p class="mw-artists-hero__eyebrow">' . esc_html( $eyebrow ) . '</p>' : '' )
			. ( '' !== $heading ? '<h' . (int) $level . ' class="mw-artists-hero__title">' . esc_html( $heading ) . '</h' . (int) $level . '>' : '' )
			. ( '' !== $description ? '<p class="mw-artists-hero__description">' . esc_html( $description ) . '</p>' : '' )
			. $this->stats( $attributes, $index )
			. '</div>'
			. '</section>';
	}

	/**
	 * Catalogue stats list (artist total plus published release total).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param ArtistIndex          $index      Browsing service.
	 */
	private function stats( array $attributes, ArtistIndex $index ): string {
		if ( ! BlockSupport::bool_attribute( $attributes, 'showStats', true ) ) {
			return '';
		}

		$artists  = $index->total();
		$releases = $index->release_total();
		if ( $artists < 1 && $releases < 1 ) {
			return '';
		}

		return '<ul class="mw-artists-hero__stats">'
			. '<li class="mw-artists-hero__stat"><strong class="mw-artists-hero__stat-value">' . esc_html( number_format_i18n( $artists ) ) . '</strong><span class="mw-artists-hero__stat-label">' . esc_html__( 'خواننده', 'music-wave-core' ) . '</span></li>'
			. '<li class="mw-artists-hero__stat"><strong class="mw-artists-hero__stat-value">' . esc_html( number_format_i18n( $releases ) ) . '</strong><span class="mw-artists-hero__stat-label">' . esc_html__( 'اثر منتشرشده', 'music-wave-core' ) . '</span></li>'
			. '</ul>';
	}
}
