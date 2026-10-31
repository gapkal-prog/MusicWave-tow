<?php
/**
 * Stateless signer for manifest / segment / key request URLs.
 *
 * Signatures cover the complete payload the edge or origin acts on
 * (`method|path|exp|kid` plus optional `sid`/`iph`), so no single query
 * parameter can be tampered with independently. The same signer is used by
 * VIP `HlsRoutes` to build per-request signed URLs and by the edge/CDN
 * configuration to verify them.
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
final class SegmentSigner {
	/**
	 * Signed parameters, in canonical order.
	 *
	 * Both `sign()` and `payload_for_verify()` iterate this exact list so the
	 * signed payload and the verifiable payload can never drift, regardless of
	 * the order the caller supplies extras in.
	 */
	private const SIGNED_PARAMS = array( 'sid', 'iph', 'kver', 'kid' );

	/** @var string */
	private $secret;
	/** @var string Key id emitted in URLs for rotation. */
	private $kid;

	public function __construct( string $secret, string $kid = '' ) {
		$this->secret = $secret;
		$this->kid    = $kid;
	}

	/**
	 * Build a signed URL by appending the signed params plus `exp` and `sig`.
	 *
	 * @param string $url    Base URL without signature params.
	 * @param int    $exp    Absolute Unix expiry.
	 * @param array  $extra  Additional signed params, e.g. `sid`, `iph`, `kver`.
	 * @return string Signed URL.
	 */
	public function sign( string $url, int $exp, array $extra = array() ): string {
		$sig = $this->signature( $this->payload( $url, $exp, $extra ) );

		$query = array();
		foreach ( self::SIGNED_PARAMS as $name ) {
			$value = $this->param_value( $name, $extra );
			if ( '' !== $value ) {
				$query[ $name ] = $value;
			}
		}
		$query['exp'] = (string) $exp;
		$query['sig'] = $sig;

		return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Verify a signed URL's `exp` and `sig`. Constant-time signature compare.
	 *
	 * @param string $url Full URL including signature params.
	 * @param int    $now   Current Unix timestamp.
	 * @return bool
	 */
	public function verify( string $url, int $now = 0 ): bool {
		if ( 0 === $now ) {
			$now = time();
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['query'] ) ) {
			return false;
		}
		parse_str( $parts['query'], $params );
		if ( ! isset( $params['exp'], $params['sig'] ) || ! is_numeric( $params['exp'] ) ) {
			return false;
		}
		if ( (int) $params['exp'] < $now ) {
			return false;
		}
		if ( '' !== $this->kid && isset( $params['kid'] ) && (string) $params['kid'] !== $this->kid ) {
			return false;
		}

		$expected = $this->signature( $this->payload_for_verify( $url, $params ) );

		return hash_equals( $expected, (string) $params['sig'] );
	}

	/**
	 * The effective signing secret.
	 */
	public function secret(): string {
		return $this->secret;
	}

	/**
	 * Canonical signed payload: method-independent, covers path + exp + every
	 * signed param the edge honours.
	 */
	private function payload( string $url, int $exp, array $extra ): string {
		$path   = wp_parse_url( $url, PHP_URL_PATH );
		$path   = is_string( $path ) ? $path : $url;
		$fields = array( $path, (string) $exp );
		foreach ( self::SIGNED_PARAMS as $name ) {
			$value = $this->param_value( $name, $extra );
			if ( '' !== $value ) {
				$fields[] = $name . '=' . $value;
			}
		}

		return implode( '|', $fields );
	}

	/**
	 * Rebuild the payload that was signed for a given query param set.
	 */
	private function payload_for_verify( string $url, array $params ): string {
		$path   = wp_parse_url( $url, PHP_URL_PATH );
		$path   = is_string( $path ) ? $path : $url;
		$fields = array( $path, isset( $params['exp'] ) ? (string) $params['exp'] : '' );
		foreach ( self::SIGNED_PARAMS as $name ) {
			if ( isset( $params[ $name ] ) && '' !== (string) $params[ $name ] ) {
				$fields[] = $name . '=' . (string) $params[ $name ];
			}
		}

		return implode( '|', $fields );
	}

	/**
	 * Value of a signed param: `kid` comes from the signer, the rest from extras.
	 *
	 * @param array<string, mixed> $extra
	 */
	private function param_value( string $name, array $extra ): string {
		if ( 'kid' === $name ) {
			return $this->kid;
		}

		return isset( $extra[ $name ] ) ? (string) $extra[ $name ] : '';
	}

	private function signature( string $payload ): string {
		return rtrim( strtr( base64_encode( hash_hmac( 'sha256', $payload, $this->secret, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of a binary HMAC, not obfuscation.
	}

	private function __clone() {}
}
