<?php
/**
 * Readiness and last-error diagnostics for the automatic remote-upload
 * pipeline. Never leaks credentials.
 *
 * @package ManaCore\MusicWave\Vip\Delivery
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Delivery;

use ManaCore\MusicWave\Core\Downloads\DirectLinkUrlValidator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @immutable
 */
final class RemoteUploadDiagnostics {
	/** @var array<string, mixed> */
	private $config;
	/** @var RemoteUploadQueue */
	private $queue;

	/** @param array<string, mixed> $config Normalized VIP settings. */
	public function __construct( array $config, RemoteUploadQueue $queue ) {
		$this->config = $config;
		$this->queue  = $queue;
	}

	/**
	 * Full snapshot for the admin settings tab.
	 *
	 * @return array<string, mixed>
	 */
	public function snapshot(): array {
		$config       = $this->config;
		$enabled      = 'enabled' === (string) ( $config['remote_upload_enabled'] ?? 'disabled' );
		$mode         = (string) ( $config['remote_upload_mode'] ?? 'webhook' );
		$endpoint     = trim( (string) ( $config['remote_upload_endpoint'] ?? '' ) );
		$bytes_needed = RemoteUploadManager::remote_bytes_required( $config );
		$adapter_ok   = $this->adapter_available( $mode );
		$endpoint_ok  = $this->endpoint_safe( $endpoint );

		return array(
			'enabled'           => $enabled,
			'mode'              => $mode,
			'endpoint'          => $this->redact( $endpoint ),
			'endpoint_https'    => '' !== $endpoint && 'https' === wp_parse_url( $endpoint, PHP_URL_SCHEME ),
			'endpoint_safe'     => $endpoint_ok,
			'bytes_required'    => $bytes_needed,
			'adapter_available' => $adapter_ok,
			'credentials_set'   => $this->credentials_set( $mode ),
			'stats'             => $this->queue->stats(),
			'active'            => $enabled && $bytes_needed && $adapter_ok && $endpoint_ok,
			'notice'            => $this->notice( $enabled, $bytes_needed, $adapter_ok, $endpoint_ok ),
		);
	}

	/**
	 * Last failure summary for one asset.
	 *
	 * @return array{status: string, error: string|null, attempts: int}
	 */
	public function last_error_for( string $asset_key ): array {
		$row = $this->queue_row( $asset_key );
		if ( null === $row ) {
			return array(
				'status'   => 'unknown',
				'error'    => null,
				'attempts' => 0,
			);
		}

		return array(
			'status'   => (string) ( $row['status'] ?? 'pending' ),
			'error'    => isset( $row['last_error'] ) && '' !== (string) $row['last_error'] ? (string) $row['last_error'] : null,
			'attempts' => absint( $row['attempts'] ?? 0 ),
		);
	}

	/**
	 * Whether the chosen adapter can run in this environment.
	 */
	private function adapter_available( string $mode ): bool {
		switch ( $mode ) {
			case 'webhook':
				return function_exists( 'wp_remote_request' );
			case 's3':
				return function_exists( 'hash_hmac' ) && function_exists( 'wp_remote_request' );
			case 'webdav':
				return function_exists( 'wp_remote_request' );
			case 'sftp':
				return function_exists( 'ssh2_connect' ) || class_exists( \phpseclib\Net\SFTP::class );
			default:
				return false;
		}
	}

	/**
	 * SSRF-safe endpoint (same rules as `DirectLinkUrlValidator`).
	 */
	private function endpoint_safe( string $endpoint ): bool {
		if ( '' === $endpoint ) {
			return false;
		}

		$validator = new DirectLinkUrlValidator();

		return null !== $validator->validate( $endpoint );
	}

	/**
	 * Whether non-secret credentials are present for the mode.
	 */
	private function credentials_set( string $mode ): bool {
		switch ( $mode ) {
			case 'webhook':
			case 'webdav':
				return '' !== (string) ( $this->config['remote_upload_auth_token'] ?? '' );
			case 's3':
				return '' !== (string) ( $this->config['remote_upload_access_key'] ?? '' )
					&& '' !== (string) ( $this->config['remote_upload_secret_key'] ?? '' )
					&& '' !== (string) ( $this->config['remote_upload_bucket'] ?? '' );
			case 'sftp':
				return '' !== trim( (string) ( $this->config['sftp_host'] ?? '' ) )
					&& '' !== trim( (string) ( $this->config['sftp_user'] ?? '' ) );
			default:
				return false;
		}
	}

	/**
	 * Human-readable notice.
	 */
	private function notice( bool $enabled, bool $bytes_needed, bool $adapter_ok, bool $endpoint_ok ): string {
		if ( ! $enabled ) {
			return 'آپلود خودکار غیرفعال است و فقط در حالتهای تحویل راهدور اثر دارد.';
		}
		if ( ! $bytes_needed ) {
			return 'حالت تحویل فعلی به بایتهای راهدور نیاز ندارد؛ آپلود بیاثر است.';
		}
		if ( ! $adapter_ok ) {
			return 'آداپتور انتخابشده در این محیط اجرا نمیشود (مثلاً ssh2 برای SFTP نصب نیست).';
		}
		if ( ! $endpoint_ok ) {
			return 'انتهای آپلود نامعتبر یا ناایمن است (فقط HTTPS، بدون IP خصوصی یا loopback).';
		}

		return 'آماده: فایلهای تازه بهصورت پسزمینه همگام میشوند.';
	}

	/**
	 * Strip query/userinfo from an endpoint so tokens never appear in the UI.
	 */
	private function redact( string $endpoint ): string {
		if ( '' === $endpoint ) {
			return '';
		}
		$parts = wp_parse_url( $endpoint );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return '';
		}

		$url = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' ) . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$url .= ':' . $parts['port'];
		}
		if ( isset( $parts['path'] ) ) {
			$url .= $parts['path'];
		}

		return $url;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function queue_row( string $asset_key ): ?array {
		$global = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		if ( ! $global instanceof \wpdb ) {
			return null;
		}

		$table = $global->prefix . 'mw_vip_upload_queue';
		$key   = 0 === strpos( $asset_key, 'vip:' ) ? substr( $asset_key, 4 ) : $asset_key;

		$row = $global->get_row(
			$global->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE asset_key = %s LIMIT 1",
				$key
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	private function __clone() {}
}
