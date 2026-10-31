<?php
/**
 * Single authorization path for every HLS playback endpoint.
 *
 * The manifest, variant, segment, and key routes all need the exact same
 * three checks — a valid signed URL, a live cookie-bound session bound to the
 * requested release, and a still-passing access decision. Keeping that logic in
 * one collaborator (SRP/DRY) means the endpoints only decide *what* to serve
 * after `authorize()` hands them a `StreamSession`.
 *
 * @package ManaCore\MusicWave\Vip\Hls
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Hls;

use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Access\AccessSubject;
use ManaCore\MusicWave\Core\Downloads\SegmentSigner;
use ManaCore\MusicWave\Core\Playback\StreamFingerprint;
use ManaCore\MusicWave\Core\Playback\StreamSession;
use ManaCore\MusicWave\Core\Playback\StreamSessionCookie;
use ManaCore\MusicWave\Core\Playback\StreamSessionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SessionGate {
	/** @var StreamSessionStore */
	private $store;
	/** @var StreamSessionCookie */
	private $cookie;
	/** @var SegmentSigner */
	private $signer;
	/** @var AccessPolicyEngine|null */
	private $policy;
	/** @var bool Enforce the session's client-IP binding. */
	private $bind_ip;
	/** @var bool Enforce the session's User-Agent binding. */
	private $bind_ua;

	public function __construct(
		StreamSessionStore $store,
		StreamSessionCookie $cookie,
		SegmentSigner $signer,
		?AccessPolicyEngine $policy = null,
		bool $bind_ip = false,
		bool $bind_ua = false
	) {
		$this->store   = $store;
		$this->cookie  = $cookie;
		$this->signer  = $signer;
		$this->policy  = $policy;
		$this->bind_ip = $bind_ip;
		$this->bind_ua = $bind_ua;
	}

	/**
	 * Verify a signed request URL and resolve the live session behind it.
	 *
	 * Returns null on every failure path so callers cannot accidentally leak
	 * which check failed (no entitlement oracle).
	 *
	 * @param string $url        Full request URL, including signature params.
	 * @param int    $release_id Release the request claims to address.
	 * @param mixed  $sid        Public session id from the query string.
	 * @param mixed  $exp        Signature expiry from the query string.
	 * @param mixed  $sig        Signature from the query string.
	 */
	public function authorize( string $url, int $release_id, $sid, $exp, $sig ): ?StreamSession {
		if ( ! is_string( $sid ) || '' === $sid || ! is_numeric( $exp ) || ! is_string( $sig ) || '' === $sig ) {
			return null;
		}
		if ( $release_id < 1 || ! $this->signer->verify( $url, (int) $exp ) ) {
			return null;
		}

		$session = $this->store->get_by_id( $sid );
		if ( null === $session ) {
			return null;
		}

		$token = isset( $_COOKIE[ StreamSessionCookie::NAME ] ) ? (string) $_COOKIE[ StreamSessionCookie::NAME ] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared via constant-time hash, never echoed.
		if ( ! $this->cookie->matches( $token, $session->token_hash() ) ) {
			return null;
		}
		if ( $session->is_expired() || $session->release_id() !== $release_id ) {
			return null;
		}
		if ( ! $this->fingerprint_matches( $session ) ) {
			return null;
		}
		if ( ! $this->allows( $release_id, $session->user_id() ) ) {
			return null;
		}

		return $session;
	}

	/**
	 * Whether the request matches the client the session was bound to.
	 *
	 * IP binding is on by default (anti-sharing); UA binding is opt-in because
	 * it can break third-party players. Both comparisons are constant-time and
	 * fail closed when the recorded hash is empty.
	 */
	private function fingerprint_matches( StreamSession $session ): bool {
		if ( $this->bind_ip && ! hash_equals( $session->ip_hash(), StreamFingerprint::ip_hash() ) ) {
			return false;
		}
		if ( $this->bind_ua && ! hash_equals( $session->ua_hash(), StreamFingerprint::ua_hash() ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Extend the sliding lease for a session that already passed `authorize()`.
	 */
	public function renew( StreamSession $session, int $ttl ): void {
		$this->store->renew( $session->session_id(), $session->token_hash(), $ttl );
	}

	/**
	 * Whether the access policy still permits the session's subject.
	 *
	 * A null policy (Core older than the adopter, or a filtered pipeline that
	 * removed it) fails closed: the session route is the last line of defence.
	 */
	private function allows( int $release_id, int $user_id ): bool {
		if ( null === $this->policy ) {
			return false;
		}

		return $this->policy->decide( $release_id, AccessSubject::for_user( $user_id ) )->is_allowed();
	}

	private function __clone() {}
}
