<?php
/**
 * Multi-key HMAC signing keyring (`active` / `retired`) with `wp-config.php`
 * constant override and non-autoloaded database persistence.
 *
 * Keeps remote signing secrets out of autoloaded WordPress options, prioritizes
 * `MUSIC_WAVE_VIP_REMOTE_SECRET` when defined in `wp-config.php`, and supports
 * zero-downtime key rotation via `kid` lookup and constant-time `hash_equals()`.
 *
 * @package ManaCore\MusicWave\Vip\Security
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RemoteSigningKeyring {
	public const OPTION            = 'music_wave_vip_remote_keyring';
	public const STATUS_ACTIVE     = 'active';
	public const STATUS_RETIRED    = 'retired';
	public const MIN_SECRET_LENGTH = 32;

	/**
	 * Whether `MUSIC_WAVE_VIP_REMOTE_SECRET` is defined in `wp-config.php`.
	 */
	public static function has_constant_secret(): bool {
		return '' !== self::constant_secret();
	}

	/**
	 * Return trimmed constant secret when defined, or empty string.
	 */
	public static function constant_secret(): string {
		if ( ! defined( 'MUSIC_WAVE_VIP_REMOTE_SECRET' ) ) {
			return '';
		}
		$raw = constant( 'MUSIC_WAVE_VIP_REMOTE_SECRET' );

		return is_string( $raw ) ? trim( $raw ) : '';
	}

	/**
	 * Optional `MUSIC_WAVE_VIP_REMOTE_KEY_ID` constant for CDN `kid` tagging.
	 */
	public static function constant_key_id(): string {
		if ( ! defined( 'MUSIC_WAVE_VIP_REMOTE_KEY_ID' ) ) {
			return '';
		}
		$raw = constant( 'MUSIC_WAVE_VIP_REMOTE_KEY_ID' );

		return is_scalar( $raw ) ? sanitize_key( (string) $raw ) : '';
	}

	/**
	 * Resolve the active signing key according to priority:
	 * 1) `MUSIC_WAVE_VIP_REMOTE_SECRET` constant
	 * 2) Active key in non-autoloaded `music_wave_vip_remote_keyring`
	 * 3) Legacy `remote_signing_secret` in `music_wave_vip_settings`
	 *
	 * @param array<string, mixed> $legacy_settings Stored or normalized VIP settings.
	 * @return array{kid: string, secret: string, source: string}
	 */
	public static function resolve_active_key( array $legacy_settings = array() ): array {
		$fallback_kid = isset( $legacy_settings['remote_key_id'] ) && is_scalar( $legacy_settings['remote_key_id'] )
			? sanitize_key( (string) $legacy_settings['remote_key_id'] )
			: '';

		$const_secret = self::constant_secret();
		if ( '' !== $const_secret ) {
			$const_kid = self::constant_key_id();
			return array(
				'kid'    => '' !== $const_kid ? $const_kid : $fallback_kid,
				'secret' => $const_secret,
				'source' => 'constant',
			);
		}

		$keyring    = self::load();
		$active_kid = $keyring['active_kid'];
		if ( '' !== $active_kid && isset( $keyring['keys'][ $active_kid ] ) ) {
			$entry = $keyring['keys'][ $active_kid ];
			if ( self::STATUS_ACTIVE === $entry['status'] && '' !== $entry['secret'] ) {
				return array(
					'kid'    => '' !== $fallback_kid ? $fallback_kid : $entry['kid'],
					'secret' => $entry['secret'],
					'source' => 'keyring',
				);
			}
		}

		$legacy_secret = isset( $legacy_settings['remote_signing_secret'] ) && is_scalar( $legacy_settings['remote_signing_secret'] )
			? trim( (string) $legacy_settings['remote_signing_secret'] )
			: '';
		if ( '' !== $legacy_secret ) {
			return array(
				'kid'    => $fallback_kid,
				'secret' => $legacy_secret,
				'source' => 'legacy',
			);
		}

		return array(
			'kid'    => $fallback_kid,
			'secret' => '',
			'source' => 'none',
		);
	}

	/**
	 * Load and sanitize the stored keyring without exposing raw secrets to callers
	 * that only inspect metadata via `public_summary()`.
	 *
	 * @return array{active_kid: string, keys: array<string, array{kid: string, secret: string, status: string, created_at: int, retired_at: int}>}
	 */
	public static function load(): array {
		$raw  = get_option( self::OPTION, array() );
		$raw  = is_array( $raw ) ? $raw : array();
		$keys = array();

		$raw_keys = isset( $raw['keys'] ) && is_array( $raw['keys'] ) ? $raw['keys'] : array();
		foreach ( $raw_keys as $raw_kid => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$kid    = sanitize_key( isset( $entry['kid'] ) && is_scalar( $entry['kid'] ) ? (string) $entry['kid'] : (string) $raw_kid );
			$secret = isset( $entry['secret'] ) && is_scalar( $entry['secret'] ) ? trim( (string) $entry['secret'] ) : '';
			$status = isset( $entry['status'] ) && self::STATUS_RETIRED === $entry['status'] ? self::STATUS_RETIRED : self::STATUS_ACTIVE;
			if ( '' === $kid || '' === $secret ) {
				continue;
			}
			$keys[ $kid ] = array(
				'kid'        => $kid,
				'secret'     => $secret,
				'status'     => $status,
				'created_at' => isset( $entry['created_at'] ) ? absint( $entry['created_at'] ) : 0,
				'retired_at' => isset( $entry['retired_at'] ) ? absint( $entry['retired_at'] ) : 0,
			);
		}

		$active_kid = isset( $raw['active_kid'] ) && is_scalar( $raw['active_kid'] ) ? sanitize_key( (string) $raw['active_kid'] ) : '';
		if ( '' === $active_kid || ! isset( $keys[ $active_kid ] ) || self::STATUS_ACTIVE !== $keys[ $active_kid ]['status'] ) {
			$active_kid = '';
			foreach ( $keys as $candidate_kid => $candidate ) {
				if ( self::STATUS_ACTIVE === $candidate['status'] ) {
					$active_kid = $candidate_kid;
					break;
				}
			}
		}

		return array(
			'active_kid' => $active_kid,
			'keys'       => $keys,
		);
	}

	/**
	 * Store a new active signing secret in the non-autoloaded keyring option,
	 * optionally retiring the previous active key for graceful CDN rotation.
	 *
	 * @param string $new_secret      Strong secret (>= 32 bytes).
	 * @param string $requested_kid   Optional explicit key identifier.
	 * @param bool   $retire_previous Keep previous active key in `retired` state.
	 * @param string $legacy_secret   Optional legacy secret to preserve as `retired` when migrating.
	 * @param string $legacy_kid      Optional legacy key ID.
	 * @return string Assigned active `kid`.
	 */
	public static function store_active_key(
		string $new_secret,
		string $requested_kid = '',
		bool $retire_previous = true,
		string $legacy_secret = '',
		string $legacy_kid = ''
	): string {
		$secret = trim( $new_secret );
		if ( '' === $secret ) {
			return '';
		}

		$keyring = self::load();
		$now     = time();

		// Seed legacy secret into keyring before rotation so in-flight URLs stay verifiable.
		$trimmed_legacy = trim( $legacy_secret );
		if ( $retire_previous && empty( $keyring['keys'] ) && '' !== $trimmed_legacy && ! hash_equals( $trimmed_legacy, $secret ) ) {
			$seed_kid                     = '' !== sanitize_key( $legacy_kid ) ? sanitize_key( $legacy_kid ) : 'klegacy';
			$keyring['keys'][ $seed_kid ] = array(
				'kid'        => $seed_kid,
				'secret'     => $trimmed_legacy,
				'status'     => self::STATUS_RETIRED,
				'created_at' => $now,
				'retired_at' => $now,
			);
		}

		foreach ( $keyring['keys'] as $existing_kid => $entry ) {
			if ( self::STATUS_ACTIVE !== $entry['status'] ) {
				continue;
			}
			if ( $retire_previous && ! hash_equals( $entry['secret'], $secret ) ) {
				$keyring['keys'][ $existing_kid ]['status']     = self::STATUS_RETIRED;
				$keyring['keys'][ $existing_kid ]['retired_at'] = $now;
			} elseif ( ! $retire_previous ) {
				unset( $keyring['keys'][ $existing_kid ] );
			}
		}

		$kid = sanitize_key( $requested_kid );
		if ( '' === $kid || ( $retire_previous && isset( $keyring['keys'][ $kid ] ) && self::STATUS_RETIRED === $keyring['keys'][ $kid ]['status'] ) ) {
			$kid = 'k' . gmdate( 'Ymd' ) . substr( hash( 'sha256', $secret . '|' . (string) $now ), 0, 6 );
		}

		$keyring['keys'][ $kid ] = array(
			'kid'        => $kid,
			'secret'     => $secret,
			'status'     => self::STATUS_ACTIVE,
			'created_at' => $now,
			'retired_at' => 0,
		);
		$keyring['active_kid']   = $kid;

		self::persist( $keyring );

		return $kid;
	}

	/**
	 * Remove all retired keys after the rotation window has elapsed.
	 */
	public static function purge_retired_keys(): int {
		$keyring = self::load();
		$removed = 0;
		foreach ( $keyring['keys'] as $kid => $entry ) {
			if ( self::STATUS_RETIRED === $entry['status'] ) {
				unset( $keyring['keys'][ $kid ] );
				++$removed;
			}
		}
		if ( $removed > 0 ) {
			self::persist( $keyring );
		}

		return $removed;
	}

	/**
	 * Clear all stored keys (used when admin checks "Clear stored signing secret").
	 */
	public static function clear_all(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Constant-time HMAC-SHA256 verification across active and retired keys.
	 *
	 * Matches the canonical payload `base_and_path|expires|mode|kid` generated by
	 * `RemoteRedirectProvider::create_url()`.
	 *
	 * @param string               $unsigned_url    Canonical HTTPS URL without query string.
	 * @param int                  $expires         Unix expiration timestamp.
	 * @param string               $mode            `'download'` or `'stream'`.
	 * @param string               $signature       Received base64url HMAC signature.
	 * @param string               $kid             Optional key identifier from query string.
	 * @param array<string, mixed> $legacy_settings Optional settings array for legacy fallback.
	 * @param int                  $max_retired_age Optional max seconds since retirement (`0` = any non-revoked retired key).
	 */
	public static function verify_signature(
		string $unsigned_url,
		int $expires,
		string $mode,
		string $signature,
		string $kid = '',
		array $legacy_settings = array(),
		int $max_retired_age = 0
	): bool {
		if ( '' === $signature || '' === $unsigned_url || $expires < time() ) {
			return false;
		}
		$clean_mode = in_array( $mode, array( 'download', 'stream' ), true ) ? $mode : '';
		if ( '' === $clean_mode ) {
			return false;
		}
		$clean_kid  = sanitize_key( $kid );
		$candidates = self::verification_candidates( $clean_kid, $legacy_settings, $max_retired_age );
		if ( empty( $candidates ) ) {
			return false;
		}

		$payload = $unsigned_url . '|' . $expires . '|' . $clean_mode . '|' . $clean_kid;
		$matched = false;
		foreach ( $candidates as $secret ) {
			if ( strlen( $secret ) < self::MIN_SECRET_LENGTH ) {
				continue;
			}
			$expected = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $payload, $secret, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			if ( hash_equals( $expected, $signature ) ) {
				$matched = true;
			}
		}

		return $matched;
	}

	/**
	 * Safe, secret-free metadata summary for admin UI and diagnostics.
	 *
	 * @param array<string, mixed> $legacy_settings Current VIP settings.
	 * @return array{source: string, active_kid: string, has_secret: bool, secret_strong: bool, retired_kids: array<int, string>}
	 */
	public static function public_summary( array $legacy_settings = array() ): array {
		$active       = self::resolve_active_key( $legacy_settings );
		$keyring      = self::load();
		$retired_kids = array();
		foreach ( $keyring['keys'] as $kid => $entry ) {
			if ( self::STATUS_RETIRED === $entry['status'] ) {
				$retired_kids[] = $kid;
			}
		}

		return array(
			'source'        => $active['source'],
			'active_kid'    => $active['kid'],
			'has_secret'    => '' !== $active['secret'],
			'secret_strong' => strlen( $active['secret'] ) >= self::MIN_SECRET_LENGTH,
			'retired_kids'  => $retired_kids,
		);
	}

	/**
	 * @param array<string, mixed> $keyring Keyring payload.
	 */
	private static function persist( array $keyring ): void {
		// Delete first if updating from a state where autoload might have been set,
		// then persist strictly with autoload = false.
		update_option( self::OPTION, $keyring, false );
	}

	/**
	 * @param array<string, mixed> $legacy_settings Optional legacy settings.
	 * @return array<int, string>
	 */
	private static function verification_candidates( string $kid, array $legacy_settings, int $max_retired_age ): array {
		$secrets = array();

		$const_secret = self::constant_secret();
		if ( '' !== $const_secret ) {
			$secrets[] = $const_secret;
		}

		$keyring = self::load();
		if ( '' !== $kid && isset( $keyring['keys'][ $kid ] ) ) {
			$entry = $keyring['keys'][ $kid ];
			if ( self::STATUS_ACTIVE === $entry['status'] || self::retired_within_grace( $entry, $max_retired_age ) ) {
				$secrets[] = $entry['secret'];
			}
		} else {
			foreach ( $keyring['keys'] as $entry ) {
				if ( self::STATUS_ACTIVE === $entry['status'] || self::retired_within_grace( $entry, $max_retired_age ) ) {
					$secrets[] = $entry['secret'];
				}
			}
		}

		if ( isset( $legacy_settings['remote_signing_secret'] ) && is_scalar( $legacy_settings['remote_signing_secret'] ) ) {
			$legacy = trim( (string) $legacy_settings['remote_signing_secret'] );
			if ( '' !== $legacy ) {
				$secrets[] = $legacy;
			}
		}

		return array_values( array_unique( $secrets ) );
	}

	/**
	 * @param array{status: string, retired_at: int} $entry           Keyring entry.
	 * @param int                                    $max_retired_age Max grace window in seconds.
	 */
	private static function retired_within_grace( array $entry, int $max_retired_age ): bool {
		if ( self::STATUS_RETIRED !== $entry['status'] ) {
			return false;
		}
		if ( $max_retired_age <= 0 || $entry['retired_at'] <= 0 ) {
			return true;
		}

		return ( time() - $entry['retired_at'] ) <= $max_retired_age;
	}
}
