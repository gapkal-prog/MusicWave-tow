<?php
/**
 * Single source of truth for per-term curation hues.
 *
 * Every term surface (term hero, taxonomy shelf tiles, artist cards,
 * playlist fallback art, release taxonomy chips) resolves its colour from
 * the term's own ID through this class, so one term renders with the same
 * hue everywhere — in cards, headers, banners, and archives — regardless of
 * list position, sort order, or which block renders it. The palette itself
 * lives in musicwave/assets/css/hues.css (8 steps); this class only maps a
 * term to a numbered modifier.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Catalog;

use WP_Term;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TermHue {
	/** Shared hue scale size — keep in step with musicwave/assets/css/hues.css. */
	private const HUE_STEPS = 8;

	/**
	 * Hue step for a numeric term ID (0 means "no hue").
	 *
	 * The modulo is stable for the lifetime of the term: creating or deleting
	 * unrelated terms, or reordering shelves, never moves a term's colour.
	 *
	 * @param int $term_id Term ID.
	 * @return int Hue step between 1 and 8, or 0 for an unknown ID.
	 */
	public static function for_id( int $term_id ): int {
		$term_id = absint( $term_id );

		return 0 === $term_id ? 0 : ( $term_id % self::HUE_STEPS ) + 1;
	}

	/**
	 * Hue step for a term object.
	 *
	 * @param WP_Term $term Term.
	 * @return int Hue step between 1 and 8, or 0 when the term has no ID.
	 */
	public static function for_term( WP_Term $term ): int {
		return self::for_id( (int) $term->term_id );
	}

	/**
	 * Canonical class modifier carrying a hue step.
	 *
	 * All surfaces emit this single class (plus their own BEM modifiers) so
	 * hues.css needs exactly one numbered rule set instead of per-surface
	 * copies; legacy `mw-hue-N`, `mw-term-hero--hue-N`, and
	 * `mw-terms-shelf__tile--hue-N` classes stay mapped as aliases.
	 *
	 * @param int $hue Hue step.
	 * @return string Class modifier, or '' when the step is out of range.
	 */
	public static function modifier( int $hue ): string {
		return $hue >= 1 && $hue <= self::HUE_STEPS ? 'mw-term-hue-' . absint( $hue ) : '';
	}
}
