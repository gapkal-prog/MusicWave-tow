<?php
/**
 * Single source of truth for validating direct (external HTTPS) download URLs.
 *
 * Pure, WordPress-agnostic, and unit-testable. Every layer that accepts a
 * `direct:` URL (schema sanitizer, REST routes, editor JS mirror, delivery
 * router) must use this validator.
 *
 * @package ManaCore\MusicWave\Core\Downloads
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @immutable
 */
final class DirectLinkUrlValidator {
	/** Maximum URL length. */
	public const MAX_LENGTH = 2048;

	/** @var list<string> Hosts explicitly allowed (wildcard prefix like *.cdn.example.com supported). */
	private $allowed_hosts;

	/**
	 * @param list<string> $allowed_hosts Optional host allowlist. Empty means any public HTTPS host is allowed.
	 */
	public function __construct( array $allowed_hosts = array() ) {
		$this->allowed_hosts = array_values( array_filter( array_map( 'strtolower', $allowed_hosts ) ) );
	}

	/**
	 * Validate and sanitize a direct-link URL.
	 *
	 * @return string|null Sanitized URL, or null when invalid.
	 */
	public function validate( string $url ): ?string {
		// 1. trim, length, control characters, spaces/backslashes (against CRLF/header splitting).
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > self::MAX_LENGTH ) {
			return null;
		}
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $url ) ) {
			return null;
		}
		if ( false !== strpos( $url, ' ' ) || false !== strpos( $url, '\\' ) ) {
			return null;
		}

		// 2. Parse via wp_parse_url; scheme must be exactly https (case-insensitive).
		$parsed = wp_parse_url( $url );
		if ( false === $parsed || ! isset( $parsed['scheme'] ) || 'https' !== strtolower( (string) $parsed['scheme'] ) ) {
			return null;
		}

		// 3. Host present and lowercase; no userinfo; port only empty or 443.
		if ( ! isset( $parsed['host'] ) || '' === (string) $parsed['host'] ) {
			return null;
		}
		$host = strtolower( (string) $parsed['host'] );
		if ( isset( $parsed['user'] ) ) {
			return null;
		}
		if ( isset( $parsed['port'] ) && 443 !== (int) $parsed['port'] ) {
			return null;
		}

		// 4. ASCII/punycode host only (against homograph attacks).
		if ( '' === $host || ! $this->is_ascii_host( $host ) ) {
			return null;
		}

		// 5. Reject IP literals, localhost, single-label, and private suffixes.
		if ( $this->is_blocked_host( $host ) ) {
			return null;
		}

		// 6. Allowlist check when configured (wildcard prefix support).
		if ( ! empty( $this->allowed_hosts ) && ! $this->host_matches_allowlist( $host ) ) {
			return null;
		}

		// 7. Drop fragment; rebuild and return. We have already validated
		// scheme, host, port, path safety and allowlist above, so the URL is
		// safe to return directly without esc_url_raw() which could alter
		// percent encoding (e.g. %20, %28, %29) needed for direct delivery.
		if ( isset( $parsed['fragment'] ) ) {
			unset( $parsed['fragment'] );
		}

		return $this->rebuild_url( $parsed );
	}

	/**
	 * Whether the host is pure ASCII (no punycode/IDN homographs).
	 */
	private function is_ascii_host( string $host ): bool {
		return (string) mb_convert_encoding( $host, 'ASCII', 'UTF-8' ) === $host;
	}

	/**
	 * Whether a host is blocked: IP literal (v4/v6, shorthand/decimal/hex/octal),
	 * localhost, single-label, or .local/.internal/.localhost suffix.
	 */
	private function is_blocked_host( string $host ): bool {
		if ( 'localhost' === $host ) {
			return true;
		}
		// Strip IPv6 brackets so the literal checks below see the raw address.
		if ( $this->is_ip_literal( trim( $host, '[]' ) ) ) {
			return true;
		}
		$parts = explode( '.', $host );
		if ( count( $parts ) < 2 ) {
			return true;
		}
		$suffix = strtolower( end( $parts ) );
		if ( in_array( $suffix, array( 'local', 'internal', 'localhost' ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether the host is an IP literal in any form the browser/OS resolves to
	 * an address: canonical v4/v6, `127.1`-style shorthand, `2130706433`
	 * decimal, `0x7f000001` hex, `0177.0.0.1` octal, `::ffff:127.0.0.1`.
	 */
	private function is_ip_literal( string $host ): bool {
		if ( '' !== $host && false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		return 1 === preg_match( '/^(0[xX][0-9a-fA-F]+|\d+)(\.(0[xX][0-9a-fA-F]+|\d+)){0,3}$/', $host );
	}

	/**
	 * Validate a URL that will be requested server-side (upload endpoints),
	 * additionally resolving DNS so a public-looking hostname pointing at a
	 * private/loopback/metadata address is rejected (SSRF).
	 *
	 * @return string|null Sanitized URL, or null when invalid or non-public.
	 */
	public function validate_endpoint( string $url ): ?string {
		$validated = $this->validate( $url );
		if ( null === $validated ) {
			return null;
		}

		$host = wp_parse_url( $validated, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host || ! $this->resolves_public( $host ) ) {
			return null;
		}

		return $validated;
	}

	/**
	 * Whether every A/AAAA record for a host is a public address. Unresolvable
	 * hosts fail closed.
	 */
	private function resolves_public( string $host ): bool {
		$host = trim( $host, '[]' );
		if ( '' === $host ) {
			return false;
		}

		$ips = array();
		$v4  = gethostbynamel( $host );
		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}
		$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS may legitimately fail; handled below.
		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}

		if ( array() === $ips ) {
			return false;
		}
		foreach ( $ips as $ip ) {
			if ( $this->is_blocked_ip( $ip ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether an IP is private, loopback, link-local, or otherwise reserved.
	 */
	private function is_blocked_ip( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		return false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Whether the host matches the allowlist (wildcard prefix only).
	 */
	private function host_matches_allowlist( string $host ): bool {
		foreach ( $this->allowed_hosts as $allowed ) {
			$allowed = trim( $allowed );
			if ( '' === $allowed ) {
				continue;
			}
			if ( 0 === strpos( $allowed, '*.' ) ) {
				$suffix = substr( $allowed, 2 );
				// *.cdn.example.com matches cdn.example.com and www.cdn.example.com,
				// but NOT cdn.example.com.evil.net.
				if ( $host === $suffix || 0 === strpos( $host, '.' . $suffix ) ) {
					return true;
				}
				continue;
			}
			if ( $host === $allowed ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Rebuild a URL from parsed parts without fragment.
	 *
	 * `wp_parse_url()` / `parse_url()` return path and query already
	 * percent-encoded; `rawurlencode()` would double-encode existing `%`
	 * sequences (e.g. `%20` → `%2520`), breaking direct external URLs.
	 * The validator already rejects spaces, control characters, backslashes,
	 * and unsafe hosts before this point, so the preserved encoding is safe.
	 */
	private function rebuild_url( array $parsed ): string {
		$url = (string) $parsed['scheme'] . '://' . (string) $parsed['host'];
		if ( isset( $parsed['port'] ) ) {
			$url .= ':' . (string) $parsed['port'];
		}
		if ( isset( $parsed['path'] ) ) {
			// Preserve percent-encoding exactly as parsed — never double-encode.
			$url .= (string) $parsed['path'];
		}
		if ( isset( $parsed['query'] ) ) {
			$url .= '?' . (string) $parsed['query'];
		}

		return $url;
	}
}
