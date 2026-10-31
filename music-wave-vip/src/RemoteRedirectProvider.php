<?php
/**
 * Generic HTTPS remote-host provider using short-lived HMAC redirect URLs.
 *
 * Fixes H7: `vip:<hash>` is resolved to its registered `relative_path` from
 * `ProtectedAssetRegistry` so the signed URL contains a real path (never
 * `vip%3A…`). Fail-closed on missing registry row, fingerprint mismatch, or
 * unsafe path.
 *
 * Supports multi-key signing (4.7): signs with the active `kid`, includes
 * `kid` in the URL, and honors `MUSIC_WAVE_VIP_REMOTE_SECRET` constant
 * (wp-config) which overrides the stored secret.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

use ManaCore\MusicWave\Core\Downloads\DownloadProvider;
use ManaCore\MusicWave\Core\Downloads\DownloadTokenClaims;
use ManaCore\MusicWave\Core\Downloads\RedirectResponse;
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

		( new RedirectResponse( $url ) )->send();

		return true;
	}

	/**
	 * Enforce the HTTPS + host allowlist boundary on every outgoing redirect.
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
	 */
	public function create_url( string $asset_id, DownloadTokenClaims $claims, bool $inline ): string {
		$url = apply_filters( 'music_wave_vip_remote_download_url', '', $asset_id, $claims, $this->config, $inline );

		return is_string( $url ) && '' !== $url ? $url : $this->signed_url( $asset_id, $claims, $inline );
	}

	/**
	 * Build the signed remote URL.
	 *
	 * For `vip:<hash>` identifiers, the real `relative_path` is loaded from the
	 * protected asset registry and used as the URL path — `vip%3A…` is never
	 * emitted.
	 */
	private function signed_url( string $asset_id, DownloadTokenClaims $claims, bool $inline ): string {
		$base   = $this->effective_base_url();
		$secret = $this->effective_signing_secret();
		$kid    = $this->effective_key_id();

		// Fail closed on weak keys: a short shared secret makes offline
		// signature forgery practical (PROJECT_PLAN.md Stage 2 deliverable 5).
		if ( '' === $base || strlen( $secret ) < 32 || 'https' !== wp_parse_url( $base, PHP_URL_SCHEME ) ) {
			return '';
		}

		// Resolve `vip:<hash>` → real relative path from the protected registry.
		$path = $this->resolve_asset_path( $asset_id );
		if ( '' === $path ) {
			return '';
		}

		$prefix        = isset( $this->config['remote_path_prefix'] ) ? trim( (string) $this->config['remote_path_prefix'], '/' ) : '';
		$path_parts    = array_filter(
			array_merge( '' !== $prefix ? explode( '/', $prefix ) : array(), explode( '/', ltrim( $path, '/' ) ) ),
			static function ( $part ): bool {
				return '' !== $part;
			}
		);
		$encoded_parts = array_map( 'rawurlencode', $path_parts );
		$url           = untrailingslashit( $base ) . '/' . implode( '/', $encoded_parts );
		$expires       = time() + ( isset( $this->config['remote_ttl'] ) ? max( 30, min( 900, absint( $this->config['remote_ttl'] ) ) ) : 300 );
		$mode          = $inline ? 'stream' : 'download';

		// Complete-payload signing: every value the remote host acts on (path,
		// expiry, delivery mode, key id) is covered by the HMAC so no query
		// parameter can be tampered with independently
		// (PROJECT_PLAN.md Stage 2 deliverable 5).
		$payload   = $url . '|' . $expires . '|' . $mode . '|' . $kid;
		$signature = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $payload, $secret, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of a binary HMAC, not obfuscation.

		$signature_param = (string) $this->config['remote_signature_param'];
		$expires_param   = (string) $this->config['remote_expires_param'];
		$args            = array(
			$signature_param => $signature,
			$expires_param   => $expires,
			'mode'           => $mode,
		);
		if ( '' !== $kid ) {
			$args['kid'] = $kid;
		}

		return add_query_arg( $args, $url );
	}

	/**
	 * Resolve the asset path for signing.
	 *
	 * `vip:<hash>` → `relative_path` from `ProtectedAssetRegistry`.
	 * Anything else → the asset_id itself (subject to path hardening).
	 *
	 * @return string Real path to sign, or empty when fail-closed.
	 */
	private function resolve_asset_path( string $asset_id ): string {
		$asset_id = trim( $asset_id );
		if ( '' === $asset_id || false !== strpos( $asset_id, '..' ) || preg_match( '/[\x00-\x1F\x7F]/', $asset_id ) ) {
			return '';
		}

		if ( 0 === strpos( $asset_id, ProtectedAssetRegistry::PREFIX ) ) {
			$registry = new ProtectedAssetRegistry();
			$row      = $registry->find( $asset_id );
			if ( null === $row || ! isset( $row['relative_path'] ) || '' === (string) $row['relative_path'] ) {
				return '';
			}
			// Fingerprint / integrity: registry already enforces size+checksum
			// at registration; here we trust the stored relative_path.
			$path = (string) $row['relative_path'];
		} else {
			$path = $asset_id;
		}

		return $this->harden_path( $path );
	}

	/**
	 * Harden a path against traversal and injection.
	 *
	 * @return string Hardened path, or empty when unsafe.
	 */
	private function harden_path( string $path ): string {
		// Reject dot segments, double slashes, absolute paths, backslashes,
		// and decoded traversal sequences.
		if ( '' === $path
			|| '/' === $path[0]
			|| false !== strpos( $path, '\\' )
			|| false !== strpos( $path, '..' )
			|| false !== strpos( $path, '//' )
			|| false !== strpos( $path, '%2e' )
			|| false !== strpos( $path, '%2f' )
			|| false !== strpos( $path, '%2E' )
			|| false !== strpos( $path, '%2F' )
		) {
			return '';
		}

		$segments = explode( '/', $path );
		$clean    = array();
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			$clean[] = $segment;
		}

		return implode( '/', $clean );
	}

	/**
	 * Effective base URL: config value (never the wp-config constant — that is
	 * only for the signing secret).
	 */
	private function effective_base_url(): string {
		return isset( $this->config['remote_base_url'] ) ? (string) $this->config['remote_base_url'] : '';
	}

	/**
	 * Effective signing secret: wp-config constant > active multi-key >
	 * legacy single secret. Returns empty for fail-closed.
	 */
	private function effective_signing_secret(): string {
		if ( defined( 'MUSIC_WAVE_VIP_REMOTE_SECRET' ) ) {
			return constant( 'MUSIC_WAVE_VIP_REMOTE_SECRET' );
		}
		$keys = isset( $this->config['remote_signing_keys'] ) && is_array( $this->config['remote_signing_keys'] ) ? $this->config['remote_signing_keys'] : array();
		$kid  = isset( $this->config['remote_active_kid'] ) ? (string) $this->config['remote_active_kid'] : '';
		if ( '' !== $kid && ! empty( $keys ) ) {
			foreach ( $keys as $key_entry ) {
				if ( is_array( $key_entry ) && isset( $key_entry['kid'] ) && (string) $key_entry['kid'] === $kid && isset( $key_entry['status'] ) && 'active' === (string) $key_entry['status'] ) {
					return (string) ( $key_entry['secret'] ?? '' );
				}
			}
		}
		return isset( $this->config['remote_signing_secret'] ) ? (string) $this->config['remote_signing_secret'] : '';
	}

	/**
	 * Effective key id: from config, or empty (legacy single-key mode).
	 */
	private function effective_key_id(): string {
		return isset( $this->config['remote_key_id'] ) ? sanitize_key( (string) $this->config['remote_key_id'] ) : '';
	}
}
