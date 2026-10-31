<?php
/**
 * Shared symmetric envelope for sensitive at-rest values.
 *
 * Wraps/unwraps small secrets (HLS AES keys, remote-upload credentials) with
 * libsodium `crypto_secretbox` derived from `AUTH_KEY` + `SECURE_AUTH_SALT`
 * via HKDF. One shared implementation keeps the HLS key store and the
 * remote-upload credential store DRY: each caller passes a distinct context
 * string so the same wrapped bytes are never reusable across contexts.
 *
 * Fail-closed: if libsodium or the site keys are unavailable, every operation
 * returns null/false and callers must refuse to proceed (no plaintext
 * fallback).
 *
 * @package ManaCore\MusicWave\Core\Support
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @immutable
 */
final class SecretBoxCipher {
	/** Nonce length required by crypto_secretbox. */
	public const NONCE_LENGTH = 24;

	/** Tag length prepended to ciphertext by crypto_secretbox. */
	public const TAG_LENGTH = 16;

	/**
	 * Derive a per-context wrapping key from WordPress auth salts.
	 *
	 * @param string $context Static context string (e.g. 'mw-hls-key-wrap').
	 * @return string|null 32-byte key, or null when site salts are missing.
	 */
	public static function derive_key( string $context ): ?string {
		if ( ! self::available() ) {
			return null;
		}

		$auth_key       = defined( 'AUTH_KEY' ) && '' !== AUTH_KEY ? (string) AUTH_KEY : '';
		$secure_salt    = defined( 'SECURE_AUTH_SALT' ) && '' !== SECURE_AUTH_SALT ? (string) SECURE_AUTH_SALT : '';
		$logged_in_key  = defined( 'LOGGED_IN_KEY' ) && '' !== LOGGED_IN_KEY ? (string) LOGGED_IN_KEY : '';
		$logged_in_salt = defined( 'LOGGED_IN_SALT' ) && '' !== LOGGED_IN_SALT ? (string) LOGGED_IN_SALT : '';

		if ( '' === $auth_key || '' === $secure_salt || '' === $logged_in_key || '' === $logged_in_salt ) {
			return null;
		}

		$material = $auth_key . '|' . $secure_salt . '|' . $logged_in_key . '|' . $logged_in_salt;

		return hash_hkdf( 'sha256', $material, 32, $context );
	}

	/**
	 * Encrypt a plaintext secret with a random nonce.
	 *
	 * @param string $plaintext Secret bytes to wrap.
	 * @param string $context   Context string for key derivation.
	 * @return string|null base64url(nonce || ciphertext), or null on failure.
	 */
	public static function wrap( string $plaintext, string $context ): ?string {
		$key = self::derive_key( $context );
		if ( null === $key ) {
			return null;
		}
		$nonce = random_bytes( self::NONCE_LENGTH );
		try {
			$box = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		} catch ( \SodiumException $e ) {
			// Fail closed: a rejected key length must never yield a usable blob.
			return null;
		}

		return self::encode( $nonce . $box );
	}

	/**
	 * Decrypt a previously wrapped value.
	 *
	 * @param string $wrapped base64url(nonce || ciphertext) as produced by wrap().
	 * @param string $context   Context string used at wrap time.
	 * @return string|null Original plaintext, or null when unwrapping fails.
	 */
	public static function unwrap( string $wrapped, string $context ): ?string {
		$key = self::derive_key( $context );
		if ( null === $key ) {
			return null;
		}
		$raw = self::decode( $wrapped );
		if ( false === $raw || strlen( $raw ) < self::NONCE_LENGTH + self::TAG_LENGTH ) {
			return null;
		}
		$nonce = substr( $raw, 0, self::NONCE_LENGTH );
		$box   = substr( $raw, self::NONCE_LENGTH );

		return sodium_crypto_secretbox_open( $box, $nonce, $key );
	}

	/**
	 * Check whether this environment can wrap/unwrap secrets at all.
	 */
	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'hash_hkdf' );
	}

	private static function encode( string $binary ): string {
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url transport encoding of wrapped key material, not code obfuscation.
	}

	private static function decode( string $value ): string|false {
		$padding = strlen( $value ) % 4;
		return base64_decode( strtr( $value . ( $padding ? str_repeat( '=', 4 - $padding ) : '' ), '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- inverse of encode(): base64url transport encoding.
	}

	private function __construct() {}
	private function __clone() {}
	public function __wakeup() {}
}
