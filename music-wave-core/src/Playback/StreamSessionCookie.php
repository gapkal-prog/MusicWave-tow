<?php
/**
 * HttpOnly session cookie for playback sessions.
 *
 * The cookie value is a random token independent of the public `session_id`;
 * only `hash( 'sha256', token )` is ever stored. Cookie flags enforce
 * Secure + HttpOnly + SameSite=Lax and are scoped to the stream REST path so
 * the token is not sent to unrelated endpoints.
 *
 * @package ManaCore\MusicWave\Core\Playback
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Playback;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @immutable
 */
final class StreamSessionCookie {
	/** Path under which the stream cookie is scoped. */
	public const PATH = '/wp-json/music-wave/v1/streams/';
	/** Cookie name. */
	public const NAME = 'mw_stream_session';
	/** Random token length in bytes. */
	public const TOKEN_BYTES = 32;

	/** @var int TTL in seconds. */
	private $ttl;
	/** @var bool Whether the request is HTTPS. */
	private $secure;

	public function __construct( int $ttl, bool $secure = false ) {
		$this->ttl    = max( 30, (int) $ttl );
		$this->secure = $secure;
	}

	/**
	 * Generate a fresh random token and set the cookie.
	 *
	 * @return string The plain token (caller hashes it before storing).
	 */
	public function issue(): string {
		$token = bin2hex( random_bytes( self::TOKEN_BYTES ) );
		$this->set( $token );

		return $token;
	}

	/**
	 * Validate an incoming cookie token against the stored hash.
	 *
	 * @param string $token Plain token from $_COOKIE.
	 * @param string $token_hash Expected SHA-256 from the session row.
	 * @return bool
	 */
	public function matches( string $token, string $token_hash ): bool {
		if ( '' === $token || '' === $token_hash ) {
			return false;
		}

		return hash_equals( $token_hash, hash( 'sha256', $token ) );
	}

	/**
	 * Clear the cookie from the browser.
	 */
	public function clear(): void {
		$this->set( '', -31536000 );
	}

	/**
	 * Whether the current request is over HTTPS (cookie must be Secure-only).
	 */
	public function is_secure(): bool {
		return $this->secure;
	}

	private function set( string $token, int $expires_offset = 0 ): void {
		if ( ! function_exists( 'setcookie' ) ) {
			return;
		}
		$expires = 0 > $expires_offset ? time() + $expires_offset : time() + $this->ttl + $expires_offset;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Set-Cookie is a response header, not request validation.
		setcookie(
			self::NAME,
			$token,
			array(
				'expires'  => $expires,
				'path'     => self::PATH,
				'domain'   => '', // host-only.
				'secure'   => $this->secure,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	private function __clone() {}
}
