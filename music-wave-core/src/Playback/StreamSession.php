<?php
/**
 * Immutable value object describing an authorized media playback session.
 *
 * The public `session_id` is exposed in URLs/API responses. The browser cookie
 * carries a separate random token whose SHA-256 hash is stored (`token_hash`),
 * so leaking `session_id` alone grants nothing. Binding to user/IP/UA is
 * recorded as hashes, never raw PII.
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
final class StreamSession {
	/** Opaque public identifier exposed in URLs. */
	public const PREFIX = 'sess_';

	/** @var string */
	private $session_id;
	/** @var string SHA-256 of the browser cookie token. */
	private $token_hash;
	/** @var int WordPress user id (0 for guests). */
	private $user_id;
	/** @var int Release id the session is bound to. */
	private $release_id;
	/** @var string Opaque asset key (`vip:<32hex>` or legacy). */
	private $asset_key;
	/** @var string SHA-256 of client IP + site salt. */
	private $ip_hash;
	/** @var string SHA-256 of User-Agent (may be empty when UA binding off). */
	private $ua_hash;
	/** @var int Unix timestamp when the session was created. */
	private $created;
	/** @var int Unix timestamp when the session/lease expires. */
	private $expires;
	/** @var int Unix timestamp of the last heartbeat. */
	private $last_seen;
	/** @var int 1 when revoked, 0 otherwise. */
	private $revoked;

	public function __construct(
		string $session_id,
		string $token_hash,
		int $user_id,
		int $release_id,
		string $asset_key,
		string $ip_hash,
		string $ua_hash,
		int $created,
		int $expires,
		int $last_seen = 0,
		int $revoked = 0
	) {
		$this->session_id = $session_id;
		$this->token_hash = $token_hash;
		$this->user_id    = $user_id;
		$this->release_id = $release_id;
		$this->asset_key  = $asset_key;
		$this->ip_hash    = $ip_hash;
		$this->ua_hash    = $ua_hash;
		$this->created    = $created;
		$this->expires    = $expires;
		$this->last_seen  = $last_seen;
		$this->revoked    = $revoked;
	}

	public function session_id(): string {
		return $this->session_id;
	}

	public function token_hash(): string {
		return $this->token_hash;
	}

	public function user_id(): int {
		return $this->user_id;
	}

	public function release_id(): int {
		return $this->release_id;
	}

	public function asset_key(): string {
		return $this->asset_key;
	}

	public function ip_hash(): string {
		return $this->ip_hash;
	}

	public function ua_hash(): string {
		return $this->ua_hash;
	}

	public function created(): int {
		return $this->created;
	}

	public function expires(): int {
		return $this->expires;
	}

	public function last_seen(): int {
		return $this->last_seen;
	}

	public function is_revoked(): bool {
		return 1 === $this->revoked;
	}

	public function is_expired( int $now = 0 ): bool {
		if ( 0 === $now ) {
			$now = time();
		}
		return $this->is_revoked() || $now >= $this->expires;
	}

	/**
	 * Build from a database row array.
	 *
	 * @param array<string, mixed> $row
	 * @return self|null
	 */
	public static function from_row( array $row ): ?self {
		if ( ! isset( $row['session_id'], $row['token_hash'], $row['user_id'], $row['release_id'], $row['asset_key'], $row['ip_hash'], $row['ua_hash'], $row['created'], $row['expires'] ) ) {
			return null;
		}
		return new self(
			(string) $row['session_id'],
			(string) $row['token_hash'],
			absint( $row['user_id'] ),
			absint( $row['release_id'] ),
			(string) $row['asset_key'],
			(string) $row['ip_hash'],
			(string) $row['ua_hash'],
			(int) $row['created'],
			(int) $row['expires'],
			isset( $row['last_seen'] ) ? (int) $row['last_seen'] : 0,
			isset( $row['revoked'] ) ? (int) $row['revoked'] : 0
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'session_id' => $this->session_id,
			'token_hash' => $this->token_hash,
			'user_id'    => $this->user_id,
			'release_id' => $this->release_id,
			'asset_key'  => $this->asset_key,
			'ip_hash'    => $this->ip_hash,
			'ua_hash'    => $this->ua_hash,
			'created'    => $this->created,
			'expires'    => $this->expires,
			'last_seen'  => $this->last_seen,
			'revoked'    => $this->revoked,
		);
	}

	private function __clone() {}
}
