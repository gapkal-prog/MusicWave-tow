<?php
/**
 * Optional SFTP upload adapter.
 *
 * Only enabled when the `ssh2` PHP extension or `phpseclib` is available. When
 * unavailable, `available()` returns false and the adapter is hidden from the
 * settings UI.
 *
 * @package ManaCore\MusicWave\Vip\Delivery\Adapters
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Delivery\Adapters;

use ManaCore\MusicWave\Vip\Delivery\RemoteUploadAdapter;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @immutable
 */
final class SftpUploadAdapter implements RemoteUploadAdapter {
	/** @var array<string, mixed> */
	private $config;

	/** @param array<string, mixed> $config Normalized VIP settings. */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	public function name(): string {
		return 'sftp';
	}

	public function available(): bool {
		return function_exists( 'ssh2_connect' ) || class_exists( \phpseclib\Net\SFTP::class );
	}

	/**
	 * Remote size is the only portable equality signal over SFTP, so the
	 * checksum slot stays empty.
	 *
	 * @return array{exists: bool, checksum: string|null, size: int|null}
	 */
	public function exists( string $remote_path, string $local_checksum, int $size ) {
		unset( $local_checksum );

		$remote_size = function_exists( 'ssh2_connect' )
			? $this->ssh2_size( $remote_path )
			: $this->phpseclib_size( $remote_path );

		return array(
			'exists'   => null !== $remote_size && $remote_size === $size,
			'checksum' => null,
			'size'     => $remote_size,
		);
	}

	public function upload( string $local_path, string $remote_path, int $size ) {
		if ( function_exists( 'ssh2_connect' ) ) {
			return $this->upload_ssh2( $local_path, $remote_path, $size );
		}
		if ( class_exists( \phpseclib\Net\SFTP::class ) ) {
			return $this->upload_phpseclib( $local_path, $remote_path, $size );
		}

		return new WP_Error( 'mw_sftp_unavailable', __( 'کتابخانهٔ SFTP (ssh2/phpseclib) در دسترس نیست.', 'music-wave-vip' ) );
	}

	/**
	 * Remote deletion is not supported by this adapter: rollback after a failed
	 * verification therefore cannot succeed, and reports why.
	 */
	public function delete( string $remote_path ): WP_Error {
		unset( $remote_path );

		return new WP_Error( 'mw_sftp_delete_unsupported', __( 'حذف فایل از طریق SFTP در این نسخه پشتیبانی نمیشود.', 'music-wave-vip' ) );
	}

	public function test_connection(): array {
		if ( ! $this->available() ) {
			return array(
				'ok'    => false,
				'error' => 'پیشنیاز SFTP (ssh2 یا phpseclib) نصب نشده است.',
			);
		}

		return array(
			'ok'    => true,
			'error' => null,
		);
	}

	/**
	 * @return array{remote_path: string, checksum: string|null, size: int}|WP_Error
	 */
	private function upload_ssh2( string $local_path, string $remote_path, int $size ) {
		$connection = $this->ssh2_connect();
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		$sftp = ssh2_sftp( $connection ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $sftp ) {
			return new WP_Error( 'mw_sftp_session', __( 'ایجاد نشست SFTP ناموفق بود.', 'music-wave-vip' ) );
		}

		$remote_url = 'ssh2.sftp://' . $sftp . '/' . ltrim( $remote_path, '/' );
		if ( ! @copy( $local_path, $remote_url ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy, WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mw_sftp_upload_failed', __( 'انتقال فایل از طریق SFTP ناموفق بود.', 'music-wave-vip' ) );
		}

		return array(
			'remote_path' => $remote_path,
			'checksum'    => null,
			'size'        => $size,
		);
	}

	/**
	 * @return array{remote_path: string, checksum: string|null, size: int}|WP_Error
	 */
	private function upload_phpseclib( string $local_path, string $remote_path, int $size ) {
		$sftp = $this->phpseclib_instance();
		if ( is_wp_error( $sftp ) ) {
			return $sftp;
		}

		if ( ! $sftp->put( ltrim( $remote_path, '/' ), $local_path, \phpseclib\Net\SFTP::SOURCE_LOCAL_FILE ) ) {
			return new WP_Error( 'mw_sftp_upload_failed', __( 'انتقال فایل از طریق SFTP ناموفق بود.', 'music-wave-vip' ) );
		}

		return array(
			'remote_path' => $remote_path,
			'checksum'    => null,
			'size'        => $size,
		);
	}

	/**
	 * @return resource|WP_Error
	 */
	private function ssh2_connect() {
		$config = $this->credentials();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$callbacks = array();
		if ( '' !== $this->fingerprint() ) {
			$callbacks['hostkey'] = function ( $host, $port, $key ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				return $this->host_key_ok( (string) $key );
			};
		}

		$connection = ssh2_connect( $config['host'], $config['port'], array(), $callbacks ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $connection ) {
			return new WP_Error( 'mw_sftp_connect', __( 'اتصال SFTP برقرار نشد (یا اثر انگشت کلید میزبان مطابقت نداشت).', 'music-wave-vip' ) );
		}

		$authed = ssh2_auth_password( $connection, $config['user'], $config['password'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( true !== $authed ) {
			return new WP_Error( 'mw_sftp_auth', __( 'احراز هویت SFTP ناموفق بود.', 'music-wave-vip' ) );
		}

		return $connection;
	}

	/**
	 * @return object|WP_Error
	 */
	private function phpseclib_instance() {
		$config = $this->credentials();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$sftp = new \phpseclib\Net\SFTP( $config['host'], $config['port'] );
		if ( ! $sftp->login( $config['user'], $config['password'] ) ) {
			return new WP_Error( 'mw_sftp_auth', __( 'احراز هویت SFTP ناموفق بود.', 'music-wave-vip' ) );
		}

		// Pin the server's host key when a fingerprint is configured (anti-MITM).
		if ( '' !== $this->fingerprint() && ! $this->host_key_ok( (string) $sftp->getServerPublicHostKey() ) ) {
			return new WP_Error( 'mw_sftp_hostkey', __( 'اثر انگشت کلید میزبان SFTP مطابقت ندارد.', 'music-wave-vip' ) );
		}

		return $sftp;
	}

	/**
	 * Configured host-key fingerprint (normalized), or '' when pinning is off.
	 */
	private function fingerprint(): string {
		return trim( (string) ( $this->config['sftp_host_fingerprint'] ?? '' ) );
	}

	/**
	 * Whether a server host key matches the configured fingerprint. Accepts the
	 * key as base64 (phpseclib, ssh2) or as a raw OpenSSH blob; compares against
	 * `SHA256:<base64>` or a bare hex SHA-256, constant-time.
	 */
	private function host_key_ok( string $server_key ): bool {
		$expected = strtoupper( $this->fingerprint() );
		if ( '' === $expected ) {
			return true;
		}

		$raw = base64_decode( $server_key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- host-key transport encoding, not obfuscation.
		if ( false === $raw ) {
			$raw = $server_key; // Already a raw key blob.
		}

		$sha256_b64 = 'SHA256:' . rtrim( base64_encode( hash( 'sha256', $raw, true ) ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OpenSSH fingerprint encoding, not obfuscation.
		$hex        = strtoupper( hash( 'sha256', $raw ) );

		return hash_equals( strtoupper( $sha256_b64 ), $expected ) || hash_equals( $hex, $expected );
	}

	/**
	 * @return array{host: string, port: int, user: string, password: string}|WP_Error
	 */
	private function credentials() {
		$host     = trim( (string) ( $this->config['sftp_host'] ?? '' ) );
		$port     = absint( $this->config['sftp_port'] ?? 22 );
		$user     = trim( (string) ( $this->config['sftp_user'] ?? '' ) );
		$password = (string) ( $this->config['sftp_password'] ?? '' );

		if ( '' === $host || '' === $user ) {
			return new WP_Error( 'mw_sftp_config', __( 'تنظیمات SFTP ناقص است.', 'music-wave-vip' ) );
		}

		return array(
			'host'     => $host,
			'port'     => $port >= 1 && $port <= 65535 ? $port : 22,
			'user'     => $user,
			'password' => $password,
		);
	}

	private function ssh2_size( string $remote_path ): ?int {
		$connection = $this->ssh2_connect();
		if ( is_wp_error( $connection ) ) {
			return null;
		}
		$sftp = ssh2_sftp( $connection ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $sftp ) {
			return null;
		}

		$stat = @ssh2_sftp_stat( $sftp, '/' . ltrim( $remote_path, '/' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return is_array( $stat ) && isset( $stat['size'] ) ? (int) $stat['size'] : null;
	}

	private function phpseclib_size( string $remote_path ): ?int {
		$sftp = $this->phpseclib_instance();
		if ( is_wp_error( $sftp ) ) {
			return null;
		}

		$stat = $sftp->stat( ltrim( $remote_path, '/' ) );

		return is_array( $stat ) && isset( $stat['size'] ) ? (int) $stat['size'] : null;
	}

	private function __clone() {}
}
