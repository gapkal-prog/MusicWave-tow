<?php
/**
 * Contract for persisting and resolving playback sessions.
 *
 * Two strategies ship: a database table (atomic, recommended) and a transient
 * store (lightweight but non-atomic concurrency counting). Core never touches
 * VIP tables; VIP implements this interface for its own storage if needed.
 *
 * @package ManaCore\MusicWave\Core\Playback
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Playback;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface StreamSessionStore {
	/**
	 * Create a new session and return it. Fail-closed when storage is
	 * unavailable or the concurrency lease cannot be taken.
	 *
	 * @param string $token_hash     SHA-256 of the browser cookie token (caller
	 *                               issues the cookie first, then binds it here).
	 * @param int    $max_concurrent Maximum active leases for the same
	 *                               concurrency key. Zero disables the check.
	 * @return StreamSession|null
	 */
	public function create(
		int $user_id,
		int $release_id,
		string $asset_key,
		string $ip_hash,
		string $ua_hash,
		string $token_hash,
		int $ttl,
		int $max_concurrent = 0
	): ?StreamSession;

	/**
	 * Resolve a live session by public id.
	 *
	 * The caller is responsible for matching the browser cookie against
	 * `token_hash()` with `StreamSessionCookie::matches()`.
	 *
	 * @return StreamSession|null
	 */
	public function get_by_id( string $session_id ): ?StreamSession;

	/**
	 * Extend the lease and refresh last_seen; returns the new session or null.
	 *
	 * @param string $token_hash SHA-256 of the browser cookie token.
	 * @return StreamSession|null
	 */
	public function renew( string $session_id, string $token_hash, int $ttl ): ?StreamSession;

	/**
	 * Revoke a session (access lost, subscription cancelled, logout).
	 */
	public function revoke( string $session_id ): bool;

	/**
	 * Revoke every active session for a user (logout, password reset, access
	 * loss) so a session cannot outlive the login it was created under.
	 *
	 * @return int Number of sessions revoked.
	 */
	public function revoke_for_user( int $user_id ): int;

	/**
	 * Count active (non-revoked, non-expired) sessions bound to a user/IP key.
	 *
	 * The key is `u<user-id>` for authenticated users and `i<ip-hash>` for
	 * guests.
	 *
	 * @return int
	 */
	public function count_active( string $concurrency_key, int $now = 0 ): int;

	/**
	 * Remove expired/revoked rows older than the grace window.
	 */
	public function cleanup( int $grace_seconds ): int;

	/**
	 * Ensure the backing table exists (idempotent).
	 */
	public function ensure_table(): bool;
}
