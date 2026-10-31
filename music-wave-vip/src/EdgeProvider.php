<?php
/**
 * Edge/CDN delivery provider for `edge_signed` and `edge_hls` modes.
 *
 * Returns a short-lived HMAC-signed URL on the edge domain without a `302` to
 * the real origin, so the origin address never reaches the browser. The two
 * modes share this implementation and differ only in the advertised mode label;
 * both deliver the **original file** through the same opaque edge path, because
 * HLS is a playback transport whose manifest is served from the site-side
 * stream routes and never from a download URL.
 *
 * Session binding (cookie + IP/UA) is enforced by `DownloadRoutes` before this
 * provider is invoked; this provider only builds the signed edge URL.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

use ManaCore\MusicWave\Core\Downloads\DownloadProvider;
use ManaCore\MusicWave\Core\Downloads\DownloadTokenClaims;
use ManaCore\MusicWave\Core\Downloads\SegmentSigner;
use ManaCore\MusicWave\Core\Support\ClientIp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EdgeProvider implements DownloadProvider {
	public const MODE_SIGNED = 'edge_signed';
	public const MODE_HLS    = 'edge_hls';

	/**
	 * Opaque first path segment on the edge for the signed **file** URL. A
	 * download always points at the original file in both edge modes.
	 */
	private const FILE_PREFIX = '/s';

	/** @var SegmentSigner */
	private $signer;
	/** @var array<string, mixed> */
	private $config;
	/** @var string Advertised delivery mode (`edge_signed`|`edge_hls`). */
	private $mode;

	/**
	 * @param array<string, mixed> $config Normalized VIP settings.
	 * @param string               $mode   One of the MODE_* constants.
	 */
	public function __construct( SegmentSigner $signer, array $config, string $mode = self::MODE_SIGNED ) {
		$this->signer = $signer;
		$this->config = $config;
		$this->mode   = in_array( $mode, array( self::MODE_SIGNED, self::MODE_HLS ), true ) ? $mode : self::MODE_SIGNED;
	}

	public function deliver( string $asset_id, DownloadTokenClaims $claims ): bool {
		$url = $this->signed_edge_url( $asset_id, $claims );
		if ( '' === $url ) {
			return false;
		}

		$payload = array(
			'url'           => $url,
			'delivery'      => $this->mode,
			'expires_in'    => max( 300, absint( $this->config['signed_url_ttl'] ?? 3600 ) ),
			'origin_hidden' => true,
		);

		if ( defined( 'MUSIC_WAVE_TESTING' ) && MUSIC_WAVE_TESTING ) {
			return true;
		}

		header( 'Content-Type: application/json' );
		header( 'Cache-Control: no-store, private' );
		header( 'Referrer-Policy: no-referrer' );
		echo wp_json_encode( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON API payload.
		exit;
	}

	/**
	 * Build the signed edge URL for the asset.
	 *
	 * @return string Signed URL, or empty when fail-closed.
	 */
	private function signed_edge_url( string $asset_id, DownloadTokenClaims $claims ): string {
		$base = trim( (string) ( $this->config['edge_base_url'] ?? '' ) );
		$host = trim( (string) ( $this->config['edge_public_host'] ?? '' ) );
		$ttl  = max( 300, absint( $this->config['signed_url_ttl'] ?? 3600 ) );

		// Fail closed on a weak secret or a non-HTTPS / missing edge base.
		if ( '' === $base || 'https' !== wp_parse_url( $base, PHP_URL_SCHEME ) || strlen( $this->signer->secret() ) < 32 ) {
			return '';
		}

		$edge_host = '' !== $host ? $host : (string) wp_parse_url( $base, PHP_URL_HOST );
		if ( '' === $edge_host ) {
			return '';
		}

		$path = $this->edge_path( $asset_id );
		if ( '' === $path ) {
			return '';
		}

		return $this->signer->sign(
			'https://' . $edge_host . $path,
			time() + $ttl,
			array(
				'sid' => $claims->token_id(),
				'iph' => hash( 'sha256', ClientIp::get() ),
			)
		);
	}

	/**
	 * Opaque, random path on the edge for the asset (never the real filename).
	 */
	private function edge_path( string $asset_id ): string {
		$key = 0 === strpos( $asset_id, ProtectedAssetRegistry::PREFIX )
			? substr( $asset_id, strlen( ProtectedAssetRegistry::PREFIX ) )
			: $asset_id;
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return '';
		}

		return self::FILE_PREFIX . '/' . $key . '/' . bin2hex( random_bytes( 4 ) );
	}

	private function __clone() {}
}
