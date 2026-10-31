<?php
/**
 * Canonical same-origin URLs for the VIP HLS routes.
 *
 * `SegmentSigner` covers the URL **path**, so the issuer and the verifying
 * route must build the identical path. Both sides therefore go through this
 * helper instead of repeating the URL shape (and instead of relying on a
 * `WP_REST_Request` accessor that WordPress does not provide).
 *
 * @package ManaCore\MusicWave\Vip\Hls
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Hls;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StreamUrl {
	/** Route prefix below `home_url()`. */
	private const PREFIX = 'wp-json/music-wave/v1/streams/';

	/**
	 * URL for one stream sub-resource (`manifest`, `variant/<name>`,
	 * `segment/<file>`, or `key`).
	 *
	 * @param int    $release_id Release id in the path.
	 * @param string $suffix     Sub-resource selector appended to the path.
	 */
	public static function build( int $release_id, string $suffix ): string {
		return trailingslashit( home_url( '/' ) ) . self::PREFIX . $release_id . '/' . $suffix;
	}

	/**
	 * The same URL with the given query parameters appended verbatim.
	 *
	 * Used to rebuild the URL of an in-flight signed request so the signature
	 * can be verified against the path the issuer signed.
	 *
	 * @param int                  $release_id Release id in the path.
	 * @param string               $suffix     Sub-resource selector.
	 * @param array<string, mixed> $query      Query parameters to append.
	 */
	public static function with_query( int $release_id, string $suffix, array $query ): string {
		$url   = self::build( $release_id, $suffix );
		$query = http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );

		return '' === $query ? $url : $url . '?' . $query;
	}

	private function __construct() {}
}
