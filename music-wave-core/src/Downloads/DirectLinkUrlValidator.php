<?php
/**
 * Strict validator for controlled direct HTTPS delivery URLs.
 *
 * Enforces HTTPS-only targets, rejects credentials, fragments, control bytes,
 * localhost/internal hostnames, private/reserved/CGNAT/metadata IPv4 & IPv6
 * literals, and honors optional site host allowlists.
 *
 * @package ManaCore\MusicWave\Core\Downloads
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

use ManaCore\MusicWave\Core\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DirectLinkUrlValidator {
	public const MAX_URL_LENGTH = 2048;

	/**
	 * Validate a raw HTTPS URL and return structured status + normalized URL.
	 *
	 * @param string                  $url           Candidate URL (without `direct:` prefix).
	 * @param array<int, string>|null $allowed_hosts Optional explicit host allowlist override.
	 * @return array{valid: bool, url: string, error_code: string, message: string}
	 */
	public static function validate( string $url, ?array $allowed_hosts = null ): array {
		$trimmed = trim( $url );
		if ( '' === $trimmed ) {
			return self::fail( 'empty_url', __( 'نشانی لینک مستقیم HTTPS نمی‌تواند خالی باشد.', 'music-wave-core' ) );
		}

		if ( strlen( $trimmed ) > self::MAX_URL_LENGTH ) {
			return self::fail( 'url_too_long', __( 'طول نشانی لینک مستقیم از حد مجاز ۲۰۴۸ نویسه بیشتر است.', 'music-wave-core' ) );
		}

		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $trimmed ) ) {
			return self::fail( 'control_chars', __( 'نشانی لینک مستقیم حاوی نویسه‌های کنترلی غیرمجاز است.', 'music-wave-core' ) );
		}

		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $trimmed ) : parse_url( $trimmed ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $parts ) ) {
			return self::fail( 'malformed_url', __( 'ساختار نشانی اینترنتی واردشده معتبر نیست.', 'music-wave-core' ) );
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		if ( 'https' !== $scheme ) {
			return self::fail( 'https_required', __( 'لینک مستقیم فایل باید الزاماً با پروتکل امن https:// آغاز شود.', 'music-wave-core' ) );
		}

		if ( ( isset( $parts['user'] ) && '' !== (string) $parts['user'] ) || ( isset( $parts['pass'] ) && '' !== (string) $parts['pass'] ) ) {
			return self::fail( 'credentials_forbidden', __( 'قرار دادن نام کاربری یا گذرواژه درون نشانی لینک مستقیم مجاز نیست.', 'music-wave-core' ) );
		}

		if ( isset( $parts['fragment'] ) && '' !== (string) $parts['fragment'] ) {
			return self::fail( 'fragment_forbidden', __( 'استفاده از بخش #fragment در نشانی فایل دانلودی مجاز نیست.', 'music-wave-core' ) );
		}

		$raw_host = isset( $parts['host'] ) ? strtolower( trim( (string) $parts['host'] ) ) : '';
		if ( '' === $raw_host ) {
			return self::fail( 'missing_host', __( 'نام دامنه در نشانی لینک مستقیم یافت نشد.', 'music-wave-core' ) );
		}

		if ( ! self::is_public_host( $raw_host ) ) {
			return self::fail( 'private_or_local_host', __( 'استفاده از localhost، شبکه داخلی یا آدرس‌های IP خصوصی/رزروشده مجاز نیست.', 'music-wave-core' ) );
		}

		$hosts = null !== $allowed_hosts ? self::normalize_host_list( $allowed_hosts ) : self::configured_allowed_hosts();
		if ( ! empty( $hosts ) && ! self::host_matches_allowlist( $raw_host, $hosts ) ) {
			return self::fail( 'host_not_allowlisted', __( 'دامنه این لینک در فهرست دامنه‌های مجاز تنظیمات تحویل MusicWave قرار ندارد.', 'music-wave-core' ) );
		}

		$sanitized = esc_url_raw( $trimmed, array( 'https' ) );
		if ( '' === $sanitized || 'https' !== strtolower( (string) ( function_exists( 'wp_parse_url' ) ? wp_parse_url( $sanitized, PHP_URL_SCHEME ) : parse_url( $sanitized, PHP_URL_SCHEME ) ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			return self::fail( 'malformed_url', __( 'نشانی لینک مستقیم پس از نرمال‌سازی معتبر نبود.', 'music-wave-core' ) );
		}

		/**
		 * Optional policy hook for custom direct-link URL verification (e.g. strict DNS pinning).
		 *
		 * @param bool   $allowed   Whether the validated HTTPS URL is permitted.
		 * @param string $sanitized Normalized HTTPS URL.
		 * @param string $host      Lowercased target hostname.
		 */
		if ( ! (bool) apply_filters( 'music_wave_direct_link_validate_url', true, $sanitized, $raw_host ) ) {
			return self::fail( 'policy_rejected', __( 'نشانی لینک مستقیم توسط سیاست امنیتی سایت رد شد.', 'music-wave-core' ) );
		}

		return array(
			'valid'      => true,
			'url'        => $sanitized,
			'error_code' => '',
			'message'    => '',
		);
	}

	/**
	 * Return normalized HTTPS URL or empty string on validation failure.
	 *
	 * @param string                  $url           Candidate URL.
	 * @param array<int, string>|null $allowed_hosts Optional explicit allowlist.
	 */
	public static function sanitize( string $url, ?array $allowed_hosts = null ): string {
		$result = self::validate( $url, $allowed_hosts );

		return $result['valid'] ? $result['url'] : '';
	}

	/**
	 * Resolve normalized host allowlist from Core Settings + runtime filter.
	 *
	 * @return array<int, string>
	 */
	public static function configured_allowed_hosts(): array {
		$raw        = class_exists( Settings::class ) ? (string) Settings::get( 'direct_link_allowed_hosts' ) : '';
		$split      = '' !== trim( $raw ) ? preg_split( '/[\r\n,\s]+/', $raw ) : array();
		$list       = is_array( $split ) ? $split : array();
		$normalized = self::normalize_host_list( $list );

		/**
		 * Filter the allowed target hostnames for `direct:` HTTPS asset URLs.
		 * An empty list permits any public HTTPS domain passing SSRF/IP checks.
		 *
		 * @param array<int, string> $hosts Normalized host patterns.
		 */
		$filtered = apply_filters( 'music_wave_direct_link_allowed_hosts', $normalized );

		return is_array( $filtered ) ? self::normalize_host_list( $filtered ) : $normalized;
	}

	/**
	 * Normalize a list of hostnames or `*.domain.tld` patterns.
	 *
	 * @param array<int, mixed> $entries Raw host entries.
	 * @return array<int, string>
	 */
	public static function normalize_host_list( array $entries ): array {
		$clean = array();
		foreach ( $entries as $entry ) {
			if ( ! is_scalar( $entry ) ) {
				continue;
			}
			$item = strtolower( trim( (string) $entry ) );
			$item = rtrim( $item, '.' );
			if ( '' === $item ) {
				continue;
			}
			if ( 0 === strpos( $item, '*.' ) ) {
				$base = substr( $item, 2 );
				if ( '' !== $base && false !== strpos( $base, '.' ) && 1 === preg_match( '/^[a-z0-9.-]+$/', $base ) && self::is_public_host( $base ) ) {
					$clean[] = '*.' . $base;
				}
				continue;
			}
			if ( 1 === preg_match( '/^[a-z0-9.-]+$/', $item ) && self::is_public_host( $item ) ) {
				$clean[] = $item;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Reject localhost, internal TLDs, non-canonical numeric IPs, and private/reserved IPs.
	 */
	public static function is_public_host( string $raw_host ): bool {
		$host = rtrim( strtolower( trim( $raw_host ) ), '.' );
		if ( '' === $host ) {
			return false;
		}

		// Bracketed IPv6 literal, e.g. [::1] or [::ffff:127.0.0.1].
		if ( '[' === $host[0] || false !== strpos( $host, ':' ) ) {
			if ( '[' !== $host[0] || ']' !== substr( $host, -1 ) ) {
				return false;
			}
			$ipv6 = substr( $host, 1, -1 );
			return self::is_public_ip( $ipv6 );
		}

		$blocked_exact = array(
			'localhost',
			'localhost.localdomain',
			'ip6-localhost',
			'ip6-loopback',
			'metadata',
			'metadata.google.internal',
			'instance-data',
		);
		if ( in_array( $host, $blocked_exact, true ) ) {
			return false;
		}

		foreach ( array( '.localhost', '.local', '.internal', '.home.arpa', '.localdomain' ) as $suffix ) {
			if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return false;
			}
		}

		// Standard dotted IPv4 literal.
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return self::is_public_ipv4( $host );
		}

		// Reject alternate numeric/hex/octal IPv4 encodings (e.g. 2130706433, 0x7f000001, 0177.0.0.1).
		if ( 1 === preg_match( '/^(?:0x[0-9a-f]+|\d+)$/i', $host ) ) {
			return false;
		}
		$labels             = explode( '.', $host );
		$all_numeric_or_hex = true;
		foreach ( $labels as $label ) {
			if ( '' === $label || 1 !== preg_match( '/^(?:0x[0-9a-f]+|[0-9]+)$/i', $label ) ) {
				$all_numeric_or_hex = false;
				break;
			}
		}
		if ( $all_numeric_or_hex ) {
			return false;
		}

		// Public DNS name must contain at least one dot and valid LDH labels.
		if ( count( $labels ) < 2 ) {
			return false;
		}
		foreach ( $labels as $label ) {
			if ( '' === $label || strlen( $label ) > 63 || '-' === $label[0] || '-' === substr( $label, -1 ) || 1 !== preg_match( '/^[a-z0-9-]+$/', $label ) ) {
				return false;
			}
		}
		$tld = (string) end( $labels );
		if ( strlen( $tld ) < 2 || ctype_digit( $tld ) ) {
			return false;
		}

		return true;
	}

	private static function is_public_ip( string $ip ): bool {
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return self::is_public_ipv4( $ip );
		}

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return false;
		}

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		$packed = inet_pton( $ip );
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return false;
		}

		// Catch IPv4-mapped IPv6 (::ffff:a.b.c.d) and IPv4-compatible IPv6 (::a.b.c.d).
		$prefix_mapped = str_repeat( "\x00", 10 ) . "\xff\xff";
		$prefix_compat = str_repeat( "\x00", 12 );
		if ( 0 === strncmp( $packed, $prefix_mapped, 12 ) || 0 === strncmp( $packed, $prefix_compat, 12 ) ) {
			$ipv4 = inet_ntop( substr( $packed, 12, 4 ) );
			return is_string( $ipv4 ) && self::is_public_ipv4( $ipv4 );
		}

		// Catch NAT64 well-known prefix 64:ff9b::/96 embedding private IPv4.
		if ( 0 === strncmp( $packed, "\x00\x64\xff\x9b" . str_repeat( "\x00", 8 ), 12 ) ) {
			$ipv4 = inet_ntop( substr( $packed, 12, 4 ) );
			return is_string( $ipv4 ) && self::is_public_ipv4( $ipv4 );
		}

		// Link-local fe80::/10 and multicast ff00::/8.
		$first  = ord( $packed[0] );
		$second = ord( $packed[1] );
		if ( 0xff === $first || ( 0xfe === $first && 0x80 === ( $second & 0xc0 ) ) ) {
			return false;
		}

		return true;
	}

	private static function is_public_ipv4( string $ipv4 ): bool {
		if ( false === filter_var( $ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		$long = ip2long( $ipv4 );
		if ( false === $long ) {
			return false;
		}
		$unsigned = (int) sprintf( '%u', $long );

		// Shared Address Space / CGNAT 100.64.0.0/10 (0x64400000 .. 0x647FFFFF).
		if ( ( $unsigned & 0xFFC00000 ) === 0x64400000 ) {
			return false;
		}

		// Benchmarking 198.18.0.0/15 (0xC6120000 .. 0xC613FFFF).
		if ( ( $unsigned & 0xFFFE0000 ) === 0xC6120000 ) {
			return false;
		}

		// Multicast 224.0.0.0/4 (0xE0000000 .. 0xEFFFFFFF).
		if ( ( $unsigned & 0xF0000000 ) === 0xE0000000 ) {
			return false;
		}

		return true;
	}

	/**
	 * @param array<int, string> $allowlist Normalized host patterns.
	 */
	private static function host_matches_allowlist( string $host, array $allowlist ): bool {
		$host = rtrim( strtolower( $host ), '.' );
		foreach ( $allowlist as $pattern ) {
			if ( $pattern === $host ) {
				return true;
			}
			if ( 0 === strpos( $pattern, '*.' ) ) {
				// Leading dot preserves strict subdomain boundary matching.
				$suffix = substr( $pattern, 1 );
				if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @return array{valid: bool, url: string, error_code: string, message: string}
	 */
	private static function fail( string $code, string $message ): array {
		return array(
			'valid'      => false,
			'url'        => '',
			'error_code' => $code,
			'message'    => $message,
		);
	}
}
