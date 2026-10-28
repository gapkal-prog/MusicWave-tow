<?php
/**
 * Compatibility checks shared by public SEO integrations.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SeoCompatibility {
	/**
	 * Detect plugins that already own titles, social metadata and schema.
	 */
	public static function has_competing_plugin(): bool {
		return defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'SEOPRESS_VERSION' )
			|| defined( 'AIOSEO_VERSION' );
	}
}
