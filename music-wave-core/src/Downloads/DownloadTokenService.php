<?php
/**
 * HMAC-SHA256 signed download and stream token issuer/verifier.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DownloadTokenService {
	/** Hard ceiling on any issued token lifetime (seconds). */
	public const MAX_TTL = 900;

	/** @var string */
	private $secret;
	public function __construct( string $secret ) {
		$this->secret = $secret; }

	public function issue( int $release_id, int $user_id, int $ttl = 300, string $binding = '', string $asset_key = 'standard', string $purpose = 'download' ): string {
		$expires_at = time() + max( 30, min( $ttl, self::MAX_TTL ) );
		$purpose    = in_array( $purpose, array( 'download', 'stream' ), true ) ? $purpose : 'download';
		$payload    = array(
			'r' => $release_id,
			'u' => $user_id,
			'e' => $expires_at,
			'j' => bin2hex( random_bytes( 16 ) ),
			'b' => hash( 'sha256', $binding ),
			'a' => sanitize_key( $asset_key ),
			'p' => $purpose,
		);
		// json_encode() is used directly so the token service stays runnable in the WP-stub domain tests.
		$encoded = $this->base64url_encode( (string) json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return $encoded . '.' . $this->base64url_encode( hash_hmac( 'sha256', $encoded, $this->secret, true ) );
	}

	public function verify( string $token, string $binding = '' ): ?DownloadTokenClaims {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return null; }
		$signature = $this->base64url_decode( $parts[1] );
		$expected  = hash_hmac( 'sha256', $parts[0], $this->secret, true );
		if ( false === $signature || ! hash_equals( $expected, $signature ) ) {
			return null; }
		$json    = $this->base64url_decode( $parts[0] );
		$payload = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $payload ) || ! isset( $payload['r'], $payload['u'], $payload['e'], $payload['j'], $payload['b'], $payload['a'] ) || ! is_scalar( $payload['r'] ) || ! is_scalar( $payload['u'] ) || ! is_scalar( $payload['e'] ) || ! is_string( $payload['j'] ) || ! is_string( $payload['b'] ) || ! is_scalar( $payload['a'] ) || ! hash_equals( $payload['b'], hash( 'sha256', $binding ) ) ) {
			return null; }
		$purpose = isset( $payload['p'] ) && is_scalar( $payload['p'] ) ? sanitize_key( (string) $payload['p'] ) : 'download';
		if ( ! in_array( $purpose, array( 'download', 'stream' ), true ) ) {
			return null; }
		$claims = new DownloadTokenClaims( absint( $payload['r'] ), absint( $payload['u'] ), (int) $payload['e'], sanitize_key( $payload['j'] ), $payload['b'], sanitize_key( (string) $payload['a'] ), $purpose );
		// A user id of 0 marks an anonymous token: policy-driven open gates
		// (public releases, the VIP everyone mode, or a disabled paywall) may
		// serve guests, so only the release binding and expiry stay mandatory.
		if ( $claims->release_id() < 1 || $claims->expires_at() < time() || strlen( $claims->token_id() ) < 16 ) {
			return null; }
		return $claims;
	}

	private function base64url_encode( string $value ): string {
		// Base64url is the standard compact encoding for signed token segments, not obfuscation.
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); } // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	/** @return string|false */ private function base64url_decode( string $value ) {
		$padding = strlen( $value ) % 4;
		return base64_decode( strtr( $value . ( $padding ? str_repeat( '=', 4 - $padding ) : '' ), '-_', '+/' ), true ); } // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
}
