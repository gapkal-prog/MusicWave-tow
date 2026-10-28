<?php
/**
 * Generic HTTPS remote-host provider using short-lived HMAC redirect URLs.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

use ManaCore\MusicWave\Core\Downloads\DownloadProvider;
use ManaCore\MusicWave\Core\Downloads\DownloadTokenClaims;
use ManaCore\MusicWave\Core\Downloads\StreamableDownloadProvider;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RemoteRedirectProvider implements DownloadProvider, StreamableDownloadProvider {
	/** @var array<string, mixed> */
	private $config;

	/** @param array<string, mixed> $config */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	public function deliver( string $asset_id, DownloadTokenClaims $claims ): bool {
		return $this->redirect( $asset_id, $claims, false );
	}

	public function stream( string $asset_id, DownloadTokenClaims $claims ): bool {
		return $this->redirect( $asset_id, $claims, true );
	}

	private function redirect( string $asset_id, DownloadTokenClaims $claims, bool $inline ): bool {
		$url = $this->create_url( $asset_id, $claims, $inline );
		if ( '' === $url || ! $this->allowed_url( $url ) ) {
			return false;
		}

		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		wp_redirect( esc_url_raw( $url ), 302, 'MusicWave VIP' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Cross-host redirect is validated against the explicit HTTPS host allowlist above; wp_safe_redirect would strip the configured remote host.
		exit;
	}

	/**
	 * Enforce the HTTPS + host allowlist boundary on every outgoing redirect.
	 *
	 * Filter overrides (`music_wave_vip_remote_download_url`) previously only
	 * had to be HTTPS, letting a compromised or careless integration redirect
	 * entitled customers to an arbitrary host. The final URL host must now be
	 * the configured remote host or an explicitly allowlisted one
	 * (PROJECT_PLAN.md Stage 2 deliverable 5).
	 */
	public function allowed_url( string $url ): bool {
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return false;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}

		$allowed = array();
		$base    = isset( $this->config['remote_base_url'] ) ? (string) $this->config['remote_base_url'] : '';
		if ( '' !== $base ) {
			$allowed[] = strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) );
		}
		foreach ( isset( $this->config['remote_allowed_hosts'] ) && is_array( $this->config['remote_allowed_hosts'] ) ? $this->config['remote_allowed_hosts'] : array() as $extra ) {
			$allowed[] = strtolower( trim( (string) $extra ) );
		}
		$allowed = array_values( array_filter( array_unique( $allowed ) ) );

		return in_array( $host, $allowed, true );
	}

	/**
	 * Build the configured HTTPS URL without sending a redirect.
	 *
	 * This is useful for integration tests and provider-specific extensions;
	 * callers must still use Core's entitlement and token flow for delivery.
	 */
	public function create_url( string $asset_id, DownloadTokenClaims $claims, bool $inline ): string {
		$url = apply_filters( 'music_wave_vip_remote_download_url', '', $asset_id, $claims, $this->config, $inline );

		return is_string( $url ) && '' !== $url ? $url : $this->signed_url( $asset_id, $claims, $inline );
	}

	private function signed_url( string $asset_id, DownloadTokenClaims $claims, bool $inline ): string {
		$base   = isset( $this->config['remote_base_url'] ) ? (string) $this->config['remote_base_url'] : '';
		$secret = isset( $this->config['remote_signing_secret'] ) ? (string) $this->config['remote_signing_secret'] : '';
		// Fail closed on weak keys: a short shared secret makes offline
		// signature forgery practical (PROJECT_PLAN.md Stage 2 deliverable 5).
		if ( '' === $base || strlen( $secret ) < 32 || 'https' !== wp_parse_url( $base, PHP_URL_SCHEME ) ) {
			return '';
		}

		$asset_id = trim( $asset_id );
		if ( '' === $asset_id || false !== strpos( $asset_id, '..' ) || preg_match( '/[\x00-\x1F\x7F]/', $asset_id ) ) {
			return '';
		}
		$prefix        = isset( $this->config['remote_path_prefix'] ) ? trim( (string) $this->config['remote_path_prefix'], '/' ) : '';
		$path_parts    = array_filter(
			array_merge( '' !== $prefix ? explode( '/', $prefix ) : array(), explode( '/', ltrim( $asset_id, '/' ) ) ),
			static function ( $part ): bool {
				return '' !== $part;
			}
		);
		$encoded_parts = array_map( 'rawurlencode', $path_parts );
		$url           = untrailingslashit( $base ) . '/' . implode( '/', $encoded_parts );
		$expires       = time() + ( isset( $this->config['remote_ttl'] ) ? max( 30, min( 900, absint( $this->config['remote_ttl'] ) ) ) : 300 );
		$mode          = $inline ? 'stream' : 'download';
		$key_id        = isset( $this->config['remote_key_id'] ) ? sanitize_key( (string) $this->config['remote_key_id'] ) : '';

		// Complete-payload signing: every value the remote host acts on (path,
		// expiry, delivery mode, key id) is covered by the HMAC so no query
		// parameter can be tampered with independently
		// (PROJECT_PLAN.md Stage 2 deliverable 5).
		$payload   = $url . '|' . $expires . '|' . $mode . '|' . $key_id;
		$signature = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $payload, $secret, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of a binary HMAC, not obfuscation.
		$args      = array(
			(string) $this->config['remote_expires_param'] => $expires,
			(string) $this->config['remote_signature_param'] => $signature,
			'mode'                                         => $mode,
		);
		if ( '' !== $key_id ) {
			$args['kid'] = $key_id;
		}

		return add_query_arg( $args, $url );
	}
}
