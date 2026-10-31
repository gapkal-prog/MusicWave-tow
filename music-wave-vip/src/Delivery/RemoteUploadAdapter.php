<?php
/**
 * Contract for uploading a protected asset to a remote download server.
 *
 * One interface, multiple interchangeable implementations (webhook, S3,
 * WebDAV, SFTP). Adding a new upload method never touches the queue or
 * manager logic (OCP).
 *
 * @package ManaCore\MusicWave\Vip\Delivery
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Delivery;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface RemoteUploadAdapter {
	/** Adapter name shown in diagnostics/UI. */
	public function name(): string;

	/**
	 * Whether this adapter is usable in the current environment (e.g. SSH2
	 * extension loaded for SFTP).
	 */
	public function available(): bool;

	/**
	 * Check whether the remote object already exists with the expected size
	 * (used for idempotent dedupe).
	 *
	 * Most object stores return an ETag that is not a SHA-256 of the content
	 * (S3 uses MD5), so size is the portable equality signal. When the protocol
	 * can supply a trustworthy SHA-256 (SFTP), it is returned in `checksum`.
	 *
	 * @param string $remote_path    Destination object key/path.
	 * @param string $local_checksum SHA-256 of the local file.
	 * @param int    $size           Expected byte size of the local file.
	 * @return array{exists: bool, checksum: string|null, size: int|null}|WP_Error
	 */
	public function exists( string $remote_path, string $local_checksum, int $size );

	/**
	 * Stream-upload a local file to the remote path.
	 *
	 * @param string $local_path
	 * @param string $remote_path
	 * @param int    $size
	 * @return array{remote_path: string, checksum: string|null, size: int|null}|WP_Error
	 */
	public function upload( string $local_path, string $remote_path, int $size );

	/**
	 * Delete a remote object (used for rollback on failed verification).
	 *
	 * @return bool|WP_Error
	 */
	public function delete( string $remote_path );

	/**
	 * Verify adapter credentials and endpoint reachability.
	 *
	 * @return array{ok: bool, error: string|null}
	 */
	public function test_connection(): array;
}
