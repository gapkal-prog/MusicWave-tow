<?php
/**
 * Orchestrates automatic, idempotent upload of protected assets to a remote
 * download server.
 *
 * Decides whether an asset needs uploading, drives the injected adapter,
 * verifies checksum/size, and records state. Fail-closed: on weak/missing
 * credentials or an unsafe endpoint, nothing is sent.
 *
 * @package ManaCore\MusicWave\Vip\Delivery
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Delivery;

use ManaCore\MusicWave\Core\Downloads\DirectLinkUrlValidator;
use ManaCore\MusicWave\Vip\ProtectedAssetRegistry;
use ManaCore\MusicWave\Vip\ProtectedAssetStorage;
use WP_Error;
use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @immutable
 */
final class RemoteUploadManager {
	/** @var RemoteUploadAdapter */
	private $adapter;
	/** @var RemoteUploadQueue */
	private $queue;
	/** @var ProtectedAssetStorage */
	private $storage;
	/** @var array<string, mixed> */
	private $config;
	/** @var wpdb|null */
	private $wpdb;
	/** @var string */
	private $assets_table;

	public function __construct(
		RemoteUploadAdapter $adapter,
		RemoteUploadQueue $queue,
		?ProtectedAssetStorage $storage = null,
		array $config = array(),
		?wpdb $wpdb = null
	) {
		$global             = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof wpdb ? $GLOBALS['wpdb'] : null;
		$this->adapter      = $adapter;
		$this->queue        = $queue;
		$this->storage      = null !== $storage ? $storage : new ProtectedAssetStorage();
		$this->config       = $config;
		$this->wpdb         = $wpdb instanceof wpdb ? $wpdb : $global;
		$this->assets_table = $this->wpdb instanceof wpdb ? $this->wpdb->prefix . 'mw_vip_assets' : 'mw_vip_assets';
	}

	/**
	 * Whether the active delivery provider needs remote bytes (and thus
	 * automatic upload applies).
	 *
	 * @param array<string, mixed> $config Normalized VIP settings.
	 */
	public static function remote_bytes_required( array $config ): bool {
		if ( 'enabled' !== (string) ( $config['remote_upload_enabled'] ?? 'disabled' ) ) {
			return false;
		}

		switch ( (string) ( $config['delivery_provider'] ?? 'local' ) ) {
			case 'remote_redirect':
			case 'edge_signed':
				return true;
			case 'edge_hls':
				// edge_hls needs remote bytes unless segments proxy through the site.
				return 'enabled' !== (string) ( $config['hls_proxy_segments'] ?? 'disabled' );
			default:
				return false;
		}}


	/**
	 * Sync one asset: ensure enqueued, then attempt upload.
	 *
	 * @return array{status: string, error: string|null}
	 */
	public function sync( string $asset_key ): array {
		if ( ! self::remote_bytes_required( $this->config ) ) {
			return array(
				'status' => 'skipped',
				'error'  => null,
			);
		}

		$key = $this->bare_key( $asset_key );
		if ( '' === $key ) {
			return array(
				'status' => 'failed',
				'error'  => __( 'شناسهٔ دارایی نامعتبر است.', 'music-wave-vip' ),
			);
		}

		if ( ! $this->credential_ok() ) {
			$this->queue->fail( $key, __( 'اعتبارنامه یا انتهای آپلود نامعتبر است (fail-closed).', 'music-wave-vip' ) );
			return array(
				'status' => 'failed',
				'error'  => __( 'اعتبارنامه یا انتهای آپلود نامعتبر است.', 'music-wave-vip' ),
			);
		}

		$row = $this->asset_row( $key );
		if ( null === $row ) {
			$this->queue->fail( $key, __( 'دارایی در فهرست ثبتشده یافت نشد.', 'music-wave-vip' ) );
			return array(
				'status' => 'failed',
				'error'  => __( 'دارایی یافت نشد.', 'music-wave-vip' ),
			);
		}

		$local_path = $this->storage->resolve( ProtectedAssetRegistry::PREFIX . $key );
		if ( false === $local_path || ! is_file( $local_path ) || ! is_readable( $local_path ) ) {
			$this->queue->fail( $key, __( 'فایل محلی در دسترس نیست.', 'music-wave-vip' ) );
			return array(
				'status' => 'failed',
				'error'  => __( 'فایل محلی در دسترس نیست.', 'music-wave-vip' ),
			);
		}

		$size = (int) ( $row['file_size'] ?? 0 );
		if ( $size < 1 ) {
			$this->queue->fail( $key, __( 'اندازهٔ فایل نامعتبر است.', 'music-wave-vip' ) );
			return array(
				'status' => 'failed',
				'error'  => __( 'اندازهٔ فایل نامعتبر است.', 'music-wave-vip' ),
			);
		}

		$max_mb = absint( $this->config['remote_upload_max_file_mb'] ?? 0 );
		if ( $max_mb > 0 && $size > $max_mb * 1048576 ) {
			$this->queue->fail( $key, __( 'فایل بزرگتر از حد مجاز آپلود خودکار است.', 'music-wave-vip' ) );
			return array(
				'status' => 'failed',
				'error'  => __( 'حجم فایل بیش از حد مجاز است.', 'music-wave-vip' ),
			);
		}

		$local_checksum = hash_file( 'sha256', $local_path );
		if ( false === $local_checksum ) {
			$this->queue->fail( $key, __( 'محاسبهٔ اثر انگشت فایل ناموفق بود.', 'music-wave-vip' ) );
			return array(
				'status' => 'failed',
				'error'  => __( 'محاسبهٔ اثر انگشت فایل ناموفق بود.', 'music-wave-vip' ),
			);
		}

		$remote_path = $this->remote_path_for( $local_path );

		// Dedupe: ask the adapter whether the remote already matches.
		$check = $this->adapter->exists( $remote_path, $local_checksum, $size );
		if ( is_wp_error( $check ) ) {
			$this->queue->fail( $key, $check->get_error_message() );
			return array(
				'status' => 'failed',
				'error'  => $check->get_error_message(),
			);
		}
		if ( ! empty( $check['exists'] ) && (int) ( $check['size'] ?? $size ) === $size ) {
			$this->mark_synced( $key, $remote_path );
			return array(
				'status' => 'synced',
				'error'  => null,
			);
		}

		$result = $this->adapter->upload( $local_path, $remote_path, $size );
		if ( is_wp_error( $result ) ) {
			$this->queue->increment_attempt( $key, $this->max_retries() );
			return array(
				'status' => 'failed',
				'error'  => $result->get_error_message(),
			);
		}

		// Verify when the protocol returned a trustworthy digest.
		$verify = 'enabled' === (string) ( $this->config['remote_upload_verify'] ?? 'enabled' );
		if ( $verify && isset( $result['checksum'] ) && '' !== (string) $result['checksum'] ) {
			if ( ! hash_equals( $local_checksum, (string) $result['checksum'] ) ) {
				$this->adapter->delete( $remote_path );
				$this->queue->increment_attempt( $key, $this->max_retries() );
				return array(
					'status' => 'failed',
					'error'  => __( 'تأیید اثر انگشت پس از آپلود ناموفق بود.', 'music-wave-vip' ),
				);
			}
		}

		$this->mark_synced( $key, $remote_path );

		// Optional local cleanup only after a verified sync.
		if ( 'disabled' === (string) ( $this->config['remote_upload_keep_local'] ?? 'enabled' ) ) {
			wp_delete_file( $local_path );
		}

		return array(
			'status' => 'synced',
			'error'  => null,
		);
	}


	/**
	 * Enqueue an asset for upload (idempotent: no duplicate pending rows).
	 *
	 * @return bool
	 */
	public function enqueue( string $asset_key ): bool {
		if ( ! self::remote_bytes_required( $this->config ) ) {
			return false;
		}

		$key = $this->bare_key( $asset_key );

		return '' !== $key && $this->queue->enqueue( $key );
	}


	/**
	 * Sync every registered asset that needs remote bytes (library-wide).
	 *
	 * @return array{queued: int, synced: int, failed: int}
	 */
	public function sync_all(): array {
		$wpdb  = $this->wpdb;
		$table = $this->assets_table;
		$stats = array(
			'queued' => 0,
			'synced' => 0,
			'failed' => 0,
		);

		if ( ! self::remote_bytes_required( $this->config ) || ! $this->wpdb instanceof wpdb ) {
			return $stats;
		}

		$rows = $wpdb->get_results( "SELECT asset_key FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( ! is_array( $rows ) ) {
			return $stats;
		}

		foreach ( $rows as $row ) {
			$key = isset( $row['asset_key'] ) ? (string) $row['asset_key'] : '';
			if ( '' === $key || ! $this->queue->enqueue( $key ) ) {
				continue;
			}
			$result = $this->sync( $key );
			switch ( $result['status'] ) {
				case 'synced':
					++$stats['synced'];
					break;
				case 'failed':
					++$stats['failed'];
					break;
				default:
					++$stats['queued'];
					break;
			}
		}

		return $stats;
	}


	/**
	 * Credentials/endpoint fail-closed check, scoped to the selected mode.
	 */
	private function credential_ok(): bool {
		switch ( (string) ( $this->config['remote_upload_mode'] ?? 'webhook' ) ) {
			case 'webhook':
			case 'webdav':
				$endpoint = trim( (string) ( $this->config['remote_upload_endpoint'] ?? '' ) );
				return '' !== $endpoint
					&& $this->endpoint_safe( $endpoint )
					&& '' !== (string) ( $this->config['remote_upload_auth_token'] ?? '' );
			case 's3':
				$endpoint = trim( (string) ( $this->config['remote_upload_endpoint'] ?? '' ) );
				return '' !== $endpoint
					&& $this->endpoint_safe( $endpoint )
					&& '' !== (string) ( $this->config['remote_upload_access_key'] ?? '' )
					&& '' !== (string) ( $this->config['remote_upload_secret_key'] ?? '' )
					&& '' !== (string) ( $this->config['remote_upload_bucket'] ?? '' );
			case 'sftp':
				return '' !== trim( (string) ( $this->config['sftp_host'] ?? '' ) )
					&& '' !== trim( (string) ( $this->config['sftp_user'] ?? '' ) );
			default:
				return false;
		}}


	/**
	 * Reuse `DirectLinkUrlValidator`'s host rules (SSRF protection) for the
	 * upload endpoint: HTTPS only, no private/loopback/metadata hosts, and the
	 * hostname must not resolve to a private address.
	 */
	private function endpoint_safe( string $url ): bool {
		$validator = new DirectLinkUrlValidator();

		return null !== $validator->validate_endpoint( $url );
	}


	/**
	 * @return array<string, mixed>|null
	 */
	private function asset_row( string $key ): ?array {
		$wpdb  = $this->wpdb;
		$table = $this->assets_table;
		if ( ! $this->wpdb instanceof wpdb || 1 !== preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE asset_key = %s LIMIT 1", $key ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}


	private function remote_path_for( string $local_path ): string {
		$prefix = trim( preg_replace( '/[^A-Za-z0-9._\/-]/', '', (string) ( $this->config['remote_upload_path_prefix'] ?? '' ) ), '/' );
		$suffix = basename( $local_path );

		return '' !== $prefix ? $prefix . '/' . $suffix : $suffix;
	}


	private function mark_synced( string $key, string $remote_path ): void {
		$wpdb  = $this->wpdb;
		$table = $this->assets_table;
		if ( $this->wpdb instanceof wpdb && 1 === preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$this->assets_table,
				array(
					'upload_state' => 'synced',
					'remote_path'  => $remote_path,
					'uploaded_at'  => time(),
				),
				array( 'asset_key' => $key ),
				array( '%s', '%s', '%d' ),
				array( '%s' )
			);
		}
		$this->queue->complete( $key );
	}


	private function max_retries(): int {
		return max( 1, absint( $this->config['remote_upload_max_retries'] ?? 5 ) );
	}


	/**
	 * Normalize a `vip:<32hex>` or bare `32hex` identifier to the bare key the
	 * assets table stores, or '' when malformed.
	 */
	private function bare_key( string $asset_key ): string {
		$key = 0 === strpos( $asset_key, ProtectedAssetRegistry::PREFIX )
			? substr( $asset_key, strlen( ProtectedAssetRegistry::PREFIX ) )
			: $asset_key;

		return 1 === preg_match( '/^[a-f0-9]{32}$/', $key ) ? $key : '';
	}


	private function __clone() {}
}
