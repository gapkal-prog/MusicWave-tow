<?php
/**
 * Download entitlement, rate-limit, replay, and delivery resolver.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Access\AccessSubject;
use ManaCore\MusicWave\Core\Support\Settings;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use ManaCore\MusicWave\Core\Support\ClientIp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DownloadResolver {
	/** @var ReleaseRepository */
	private $releases;
	/** @var AccessPolicyEngine */ private $policy;
	/** @var DownloadTokenService */ private $tokens;
	/** @var ReplayStore */ private $replays;
	/** @var DownloadProvider */ private $provider;
	/** @var OpaqueTicketStore */ private $tickets;
	/** @var string Last deny reason for richer REST errors. */
	private $last_deny_reason = '';

	public function last_deny_reason(): string {
		return $this->last_deny_reason;
	}

	public function __construct( ReleaseRepository $releases, AccessPolicyEngine $policy, DownloadTokenService $tokens, ReplayStore $replays, DownloadProvider $provider, ?OpaqueTicketStore $tickets = null ) {
		$this->releases = $releases;
		$this->policy   = $policy;
		$this->tokens   = $tokens;
		$this->replays  = $replays;
		$this->provider = $provider;
		$this->tickets  = null !== $tickets ? $tickets : new OpaqueTicketStore(); }

	/**
	 * Whether the current visitor may reach the delivery endpoints at all.
	 *
	 * Logged-in visitors always pass; guests pass exactly when the access
	 * policy itself allows them (public releases or a policy-level open gate
	 * such as the VIP "everyone" delivery mode).
	 */
	public function can_request( int $release_id ): bool {
		if ( $release_id < 1 ) {
			return false;
		}
		if ( ! $this->policy->decide( $release_id, AccessSubject::current() )->is_allowed() ) {
			return false;
		}
		if ( get_current_user_id() < 1 && ! (bool) apply_filters( 'music_wave_guest_delivery_allowed', true, $release_id ) ) {
			$this->last_deny_reason = 'authentication_required';
			return false;
		}

		return true;
	}

	/**
	 * Resolve configured token lifetime (in seconds) for `'download'` or `'stream'`.
	 */
	public function ttl_for( string $purpose ): int {
		if ( 'stream' === $purpose ) {
			$default = class_exists( Settings::class ) ? Settings::get_stream_token_ttl() : 900;
			$ttl     = (int) apply_filters( 'music_wave_stream_token_ttl', $default );
			return max( 60, min( 7200, $ttl > 0 ? $ttl : $default ) );
		}

		$default = class_exists( Settings::class ) ? Settings::get_token_ttl() : 300;
		$ttl     = (int) apply_filters( 'music_wave_download_token_ttl', $default );
		return max( 30, min( 3600, $ttl > 0 ? $ttl : $default ) );
	}

	public function issue( int $release_id, AccessSubject $subject, string $binding, string $asset_key = '', string $purpose = 'download' ): ?string {
		$this->last_deny_reason = '';
		if ( ! $this->policy->decide( $release_id, $subject )->is_allowed() ) {
			$this->last_deny_reason = 'entitlement';
			$this->audit( 'token_denied', $release_id, $subject->user_id(), array( 'reason' => 'entitlement' ) );
			return null; }
		if ( $subject->user_id() < 1 && ! $subject->can( 'manage_options' ) && ! (bool) apply_filters( 'music_wave_guest_delivery_allowed', true, $release_id ) ) {
			$this->last_deny_reason = 'authentication_required';
			$this->audit( 'token_denied', $release_id, 0, array( 'reason' => 'authentication_required' ) );
			return null;
		}
		$asset = $this->asset( $release_id, $asset_key );
		if ( null === $asset ) {
			$this->last_deny_reason = 'unknown_asset';
			$this->audit( 'token_denied', $release_id, $subject->user_id(), array( 'reason' => 'unknown_asset' ) );
			return null;
		}
		if ( $this->provider instanceof DeliveryRouter ) {
			if ( ! $this->provider->can_deliver_asset( $asset['asset_id'] ) ) {
				$this->last_deny_reason = DirectLinkAsset::has_direct_scheme( $asset['asset_id'] ) ? 'invalid_direct_url' : 'provider_unavailable';
				$this->audit( 'token_denied', $release_id, $subject->user_id(), array( 'reason' => $this->last_deny_reason ) );
				return null;
			}
		} elseif ( $this->provider instanceof NullDownloadProvider && ( 0 === strpos( $asset['asset_id'], 'vip:' ) || 0 === strpos( $asset['asset_id'], 'local:' ) ) ) {
			$this->last_deny_reason = 'provider_unavailable';
			$this->audit( 'token_denied', $release_id, $subject->user_id(), array( 'reason' => 'provider_unavailable' ) );
			return null;
		}
		$purpose = in_array( $purpose, array( 'download', 'stream' ), true ) ? $purpose : 'download';
		if ( 'stream' === $purpose ) {
			$can_stream = $this->provider instanceof DeliveryRouter
				? ( ReleaseDownloadCatalog::is_asset_streamable( $asset ) && $this->provider->can_stream_asset( $asset['asset_id'] ) )
				: ( $this->provider instanceof StreamableDownloadProvider );
			if ( ! $can_stream ) {
				$this->last_deny_reason = 'stream_unsupported';
				$this->audit( 'token_denied', $release_id, $subject->user_id(), array( 'reason' => 'stream_unsupported' ) );
				return null;
			}
		}
		$this->audit(
			'token_issued',
			$release_id,
			$subject->user_id(),
			array(
				'asset_key' => $asset['key'],
				'purpose'   => $purpose,
			)
		);
		$ttl = $this->ttl_for( $purpose );

		return $this->tickets->wrap( $this->tokens->issue( $release_id, $subject->user_id(), $ttl, $binding, $asset['key'], $purpose ), $ttl );
	}

	/**
	 * Accept an opaque browser ticket or (legacy, short-lived) signed token.
	 */
	private function exchange( string $token ): string {
		if ( ! OpaqueTicketStore::looks_like_ticket( $token ) ) {
			return $token;
		}
		$stored = $this->tickets->unwrap( $token );

		return null !== $stored ? $stored : '';
	}

	public function deliver( int $release_id, int $user_id, string $token, string $binding ): bool {
		$this->last_deny_reason = '';
		$claims                 = $this->tokens->verify( $this->exchange( $token ), $binding );
		if ( null === $claims || 'download' !== $claims->purpose() || $claims->release_id() !== $release_id || $claims->user_id() !== $user_id ) {
			$this->last_deny_reason = null === $claims ? 'invalid_token' : 'claim_mismatch';
			$this->audit( 'download_denied', $release_id, $user_id, array( 'reason' => $this->last_deny_reason ) );
			return false; }
		if ( $user_id < 1 && ! (bool) apply_filters( 'music_wave_guest_delivery_allowed', true, $release_id ) ) {
			$this->last_deny_reason = 'authentication_required';
			$this->audit( 'download_denied', $release_id, 0, array( 'reason' => 'authentication_required' ) );
			return false;
		}
		$subject  = AccessSubject::for_user( $user_id );
		$decision = $this->policy->decide( $release_id, $subject );
		if ( ! $decision->is_allowed() ) {
			$this->last_deny_reason = $decision->reason();
			$this->audit( 'download_denied', $release_id, $user_id, array( 'reason' => $decision->reason() ) );
			return false; }
		// Quota runs before the one-time replay token is consumed so a
		// quota-blocked delivery does not burn the user's link.
		if ( ! $this->within_daily_quota( $user_id ) ) {
			$this->last_deny_reason = 'quota_exceeded';
			$this->audit( 'download_denied', $release_id, $user_id, array( 'reason' => 'quota_exceeded' ) );
			return false; }
		$asset = $this->asset( $release_id, $claims->asset_key() );
		if ( null === $asset ) {
			$this->last_deny_reason = 'unknown_asset';
			$this->audit( 'download_denied', $release_id, $user_id, array( 'reason' => 'unknown_asset' ) );
			return false;
		}
		if ( $this->provider instanceof DeliveryRouter && ! $this->provider->can_deliver_asset( $asset['asset_id'] ) ) {
			$this->last_deny_reason = DirectLinkAsset::has_direct_scheme( $asset['asset_id'] ) ? 'invalid_direct_url' : 'provider_unavailable';
			$this->audit( 'download_denied', $release_id, $user_id, array( 'reason' => $this->last_deny_reason ) );
			return false;
		}
		if ( ! $this->replays->consume( $claims->token_id(), $claims->expires_at() ) ) {
			$this->last_deny_reason = 'replay';
			$this->audit( 'download_denied', $release_id, $user_id, array( 'reason' => 'replay' ) );
			return false; }

		// Real HTTP providers (`ProtectedFileProvider`, `RedirectResponse`) call
		// `exit` after streaming or sending a 302 redirect. A shutdown guard
		// ensures daily quota counting and `download_delivered` auditing run
		// reliably on live HTTP requests as well as non-exiting test providers.
		$returned = false;
		register_shutdown_function(
			function () use ( &$returned, $user_id, $release_id ): void {
				if ( $returned ) {
					return;
				}
				$last_error = error_get_last();
				if ( is_array( $last_error ) && in_array( (int) $last_error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
					return;
				}
				$returned = true;
				$this->count_delivery( $user_id );
				$this->audit( 'download_delivered', $release_id, $user_id );
			}
		);

		$delivered = $this->provider->deliver( $asset['asset_id'], $claims );
		$returned  = true;
		if ( $delivered ) {
			$this->count_delivery( $user_id );
		} else {
			$this->last_deny_reason = 'provider_failed';
		}
		$this->audit( $delivered ? 'download_delivered' : 'download_denied', $release_id, $user_id );
		return $delivered;
	}

	/**
	 * Optional per-user daily delivery quota (disabled by default).
	 *
	 * Persisted in a non-autoloaded database option (`mw_dl_quota_*`) rather
	 * than volatile object-cache transients so `wp_cache_flush()` cannot reset
	 * daily download quotas on sites running Redis or Memcached.
	 */
	private function within_daily_quota( int $user_id ): bool {
		$default = class_exists( Settings::class ) ? max( 0, (int) Settings::get( 'download_daily_quota' ) ) : 0;
		$quota   = (int) apply_filters( 'music_wave_download_daily_quota', $default, $user_id );
		if ( $quota < 1 ) {
			return true;
		}

		return $this->read_quota_count( $this->quota_key( $user_id ) ) < $quota;
	}

	private function count_delivery( int $user_id ): void {
		$key = $this->quota_key( $user_id );
		$ttl = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		update_option(
			$key,
			array(
				'count'      => $this->read_quota_count( $key ) + 1,
				'expires_at' => time() + $ttl,
			),
			false
		);
	}

	private function read_quota_count( string $key ): int {
		$stored = get_option( $key, null );
		if ( is_array( $stored ) && isset( $stored['count'], $stored['expires_at'] ) ) {
			if ( (int) $stored['expires_at'] < time() ) {
				delete_option( $key );
				return 0;
			}
			return max( 0, (int) $stored['count'] );
		}
		if ( is_numeric( $stored ) ) {
			return max( 0, (int) $stored );
		}

		return 0;
	}

	private function quota_key( int $user_id ): string {
		if ( $user_id > 0 ) {
			return 'mw_dl_quota_' . $user_id . '_' . gmdate( 'Ymd' );
		}

		// Guests get a per-IP bucket (mirroring DownloadRateLimiter) so one
		// visitor cannot exhaust — or hide inside — a shared global counter.
		return 'mw_dl_quota_g_' . hash( 'sha256', $this->guest_ip_identity() ) . '_' . gmdate( 'Ymd' );
	}

	/**
	 * Stable identity for guest quota buckets: validated IP via ClientIp helper.
	 */
	private function guest_ip_identity(): string {
		$ip = ClientIp::get();
		return '' !== $ip ? $ip : 'unknown';
	}

	public function stream( int $release_id, int $user_id, string $token, string $binding ): bool {
		$this->last_deny_reason = '';
		$claims                 = $this->tokens->verify( $this->exchange( $token ), $binding );
		if ( null === $claims || 'stream' !== $claims->purpose() || $claims->release_id() !== $release_id || $claims->user_id() !== $user_id ) {
			$this->last_deny_reason = null === $claims ? 'invalid_token' : 'claim_mismatch';
			$this->audit( 'stream_denied', $release_id, $user_id, array( 'reason' => $this->last_deny_reason ) );
			return false;
		}
		if ( $user_id < 1 && ! (bool) apply_filters( 'music_wave_guest_delivery_allowed', true, $release_id ) ) {
			$this->last_deny_reason = 'authentication_required';
			$this->audit( 'stream_denied', $release_id, 0, array( 'reason' => 'authentication_required' ) );
			return false;
		}
		if ( ! $this->provider instanceof StreamableDownloadProvider ) {
			$this->last_deny_reason = 'stream_unsupported';
			$this->audit( 'stream_denied', $release_id, $user_id, array( 'reason' => 'stream_unsupported' ) );
			return false;
		}
		$decision = $this->policy->decide( $release_id, AccessSubject::for_user( $user_id ) );
		if ( ! $decision->is_allowed() ) {
			$this->last_deny_reason = $decision->reason();
			$this->audit( 'stream_denied', $release_id, $user_id, array( 'reason' => $decision->reason() ) );
			return false;
		}
		$asset = $this->asset( $release_id, $claims->asset_key() );
		if ( null === $asset ) {
			$this->last_deny_reason = 'unknown_asset';
			$this->audit( 'stream_denied', $release_id, $user_id, array( 'reason' => 'unknown_asset' ) );
			return false;
		}
		if ( $this->provider instanceof DeliveryRouter ) {
			if ( ! ReleaseDownloadCatalog::is_asset_streamable( $asset ) || ! $this->provider->can_stream_asset( $asset['asset_id'] ) ) {
				$this->last_deny_reason = 'stream_unsupported';
				$this->audit( 'stream_denied', $release_id, $user_id, array( 'reason' => 'stream_unsupported' ) );
				return false;
			}
		}

		$returned = false;
		register_shutdown_function(
			function () use ( &$returned, $user_id, $release_id ): void {
				if ( $returned ) {
					return;
				}
				$last_error = error_get_last();
				if ( is_array( $last_error ) && in_array( (int) $last_error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
					return;
				}
				$returned = true;
				$this->audit( 'stream_delivered', $release_id, $user_id );
			}
		);

		if ( ! ( $this->provider instanceof StreamableDownloadProvider ) ) {
			$returned               = true;
			$this->last_deny_reason = 'stream_not_supported';
			$this->audit( 'stream_denied', $release_id, $user_id );
			return false;
		}

		$streamed = $this->provider->stream( $asset['asset_id'], $claims );
		$returned = true;
		if ( ! $streamed ) {
			$this->last_deny_reason = 'provider_failed';
		}
		$this->audit( $streamed ? 'stream_delivered' : 'stream_denied', $release_id, $user_id );

		return $streamed;
	}

	/**
	 * Return the selected private asset with legacy single-file compatibility.
	 *
	 * @return array<string, string>|null
	 */
	private function asset( int $release_id, string $requested_key ) {
		try {
			$variants = $this->releases->get( $release_id, 'mw_download_assets' );
			$legacy   = $this->releases->get( $release_id, 'mw_download_asset_id' );
		} catch ( \InvalidArgumentException $exception ) {
			return null;
		}

		$assets = is_array( $variants ) ? $variants : array();
		if ( empty( $assets ) && is_string( $legacy ) && '' !== $legacy ) {
			$assets[] = array(
				'key'      => 'standard',
				'label'    => 'Standard download',
				'asset_id' => $legacy,
			);
		}
		if ( empty( $assets ) ) {
			return null;
		}

		$key = sanitize_key( $requested_key );
		if ( '' === $key ) {
			return isset( $assets[0] ) && is_array( $assets[0] ) ? $this->valid_asset( $assets[0] ) : null;
		}
		foreach ( $assets as $asset ) {
			if ( is_array( $asset ) && isset( $asset['key'] ) && sanitize_key( (string) $asset['key'] ) === $key ) {
				return $this->valid_asset( $asset );
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $asset Stored asset variant.
	 * @return array<string, string>|null
	 */
	private function valid_asset( array $asset ) {
		$sanitize  = static function ( string $value ): string {
			if ( function_exists( 'sanitize_text_field' ) ) {
				return sanitize_text_field( $value );
			}
			return function_exists( 'wp_strip_all_tags' ) ? trim( wp_strip_all_tags( $value ) ) : trim( (string) preg_replace( '/<[^>]*>/', '', $value ) );
		};
		$key       = isset( $asset['key'] ) ? sanitize_key( (string) $asset['key'] ) : '';
		$label     = isset( $asset['label'] ) ? $sanitize( (string) $asset['label'] ) : '';
		$asset_id  = isset( $asset['asset_id'] ) ? $sanitize( (string) $asset['asset_id'] ) : '';
		$format    = isset( $asset['format'] ) && is_scalar( $asset['format'] ) ? sanitize_key( strtolower( (string) $asset['format'] ) ) : '';
		$file_name = isset( $asset['file_name'] ) && is_scalar( $asset['file_name'] ) ? $sanitize( (string) $asset['file_name'] ) : '';

		if ( '' === $key || '' === $label || '' === $asset_id ) {
			return null;
		}

		$valid = array(
			'key'      => $key,
			'label'    => $label,
			'asset_id' => $asset_id,
		);
		if ( '' !== $format ) {
			$valid['format'] = $format;
		}
		if ( '' !== $file_name ) {
			$valid['file_name'] = $file_name;
		}

		return $valid;
	}

	/**
	 * Emit one structured, PII-free delivery audit event.
	 *
	 * @param string               $event      Event key.
	 * @param int                  $release_id Release ID.
	 * @param int                  $user_id    Acting user ID.
	 * @param array<string, mixed> $context    Structured event context
	 *                                         (reason, asset_key, purpose).
	 */
	private function audit( string $event, int $release_id, int $user_id, array $context = array() ): void {
		$context = array_merge(
			array(
				'timestamp' => time(),
			),
			$context
		);
		do_action( 'music_wave_download_event', $event, $release_id, $user_id, $context );
	}
}
