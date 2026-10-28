<?php
/**
 * Shared shelf section-header fragments.
 *
 * Three surfaces build a section header from the same three pieces — an
 * optional eyebrow, a heading, and a trailing "see all" link decorated with an
 * arrow — but each keeps its own BEM root: `mw-release-shelf__header`,
 * `mw-related-releases__section-header` and `mw-continue-listening__header`.
 * The roots are CSS contracts, so they stay where they are; only the genuinely
 * identical parts are shared here.
 *
 * `tests/card-markup.php` pins the rendered output of the two producers that
 * use this class.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SectionHeader {

	/**
	 * A trailing "see all" link.
	 *
	 * The arrow is decorative, so it is hidden from assistive technology; the
	 * visible label carries the meaning.
	 *
	 * @param string $class_name Root-specific link class, e.g. `mw-release-shelf__more`.
	 * @param string $url   Target URL. Empty means "no link".
	 * @param string $label Already-translated link label. Empty means "no link".
	 */
	public static function more_link( string $class_name, string $url, string $label ): string {
		if ( '' === $url || '' === $label ) {
			return '';
		}

		return '<a class="' . esc_attr( $class_name ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '<span aria-hidden="true">&rarr;</span></a>';
	}

	/**
	 * The release-shelf section header.
	 *
	 * Callers resolve their own default link label so each product keeps its
	 * own textdomain.
	 *
	 * @param string $eyebrow     Small label above the heading.
	 * @param string $title       Section heading.
	 * @param string $description Optional supporting copy.
	 * @param string $url         Optional "see all" URL.
	 * @param string $label       Already-resolved link label.
	 */
	public static function shelf_header( string $eyebrow, string $title, string $description = '', string $url = '', string $label = '' ): string {
		if ( '' === $eyebrow && '' === $title && '' === $description && '' === $url ) {
			return '';
		}

		return '<header class="mw-release-shelf__header"><div>'
			. ( '' !== $eyebrow ? '<span>' . esc_html( $eyebrow ) . '</span>' : '' )
			. ( '' !== $title ? '<h2>' . esc_html( $title ) . '</h2>' : '' )
			. ( '' !== $description ? '<p>' . esc_html( $description ) . '</p>' : '' )
			. '</div>' . self::more_link( 'mw-release-shelf__more', $url, $label ) . '</header>';
	}
}
