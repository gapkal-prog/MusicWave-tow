<?php
/**
 * WebDAV upload adapter (streamed PUT; best-effort MKCOL for the parent
 * collection).
 *
 * Works with Nextcloud, generic shared hosts exposing WebDAV, and any WebDAV
 * server.
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

final class WebdavUploadAdapter implements RemoteUploadAdapter {
	/** @var array<string, mixed> */
	private $config;

	/** @param array<string, mixed> $config Normalized VIP settings. */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	public function name(): string {
		return 'webdav';
	}

	public function available(): bool {
		return function_exists( 'wp_remote_request' );
	}

	/**
	 * An unreachable or missing resource is reported as "absent", not as an
	 * error: dedupe only needs to know whether the bytes are already there.
	 *
	 * @return array{exists: bool, checksum: string|null, size: int|null}
	 */
	public function exists( string $remote_path, string $local_checksum, int $size ) {
		unset( $local_checksum );

		$url = $this->url_for( $remote_path );
		if ( '' === $url ) {
			return array(
				'exists'   => false,
				'checksum' => null,
				'size'     => null,
			);
		}

		$response = wp_remote_request(
			$url,
			array( // phpcs:ignore WordPress.HTTP.HTTPRequest
			'method'             => 'HEAD',
			'timeout'            => 15,
			'reject_unsafe_urls' => true,
			'sslverify'          => ! $this->insecure_tls(),
			'redirection'        => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'exists'   => false,
				'checksum' => null,
				'size'     => null,
			);
		}

		$length = wp_remote_retrieve_header( $response, 'Content-Length' );

		return array(
			'exists'   => 200 === wp_remote_retrieve_response_code( $response ),
			'checksum' => null, // WebDAV rarely returns a trustworthy SHA-256; compare by size.
			'size'     => '' !== $length ? absint( $length ) : null,
		);
	}

	public function upload( string $local_path, string $remote_path, int $size ) {
		$url = $this->url_for( $remote_path );
		if ( '' === $url ) {
			return new WP_Error( 'mw_webdav_endpoint_empty', __( 'انتهای WebDAV تنظیم نشده است.', 'music-wave-vip' ) );
		}

		// Ensure the parent collection exists (405 = already exists, ignored).
		$parent = dirname( $url );
		if ( '' !== $parent && $parent !== $this->endpoint() ) {
			wp_remote_request(
				$parent,
				array(
					'method'             => 'MKCOL',
					'timeout'            => 15,
					'reject_unsafe_urls' => true,
					'sslverify'          => ! $this->insecure_tls(),
					'redirection'        => 0,
					'blocking'           => false,
				)
			); // phpcs:ignore WordPress.HTTP.HTTPRequest
		}

		$data = $this->read_file( $local_path );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$headers = array(
			'Content-Type'   => 'application/octet-stream',
			'Content-Length' => (string) $size,
		);
		$token   = (string) ( $this->config['remote_upload_auth_token'] ?? '' );
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_request(
			$url,
			array( // phpcs:ignore WordPress.HTTP.HTTPRequest
			'method'             => 'PUT',
			'headers'            => $headers,
			'body'               => $data,
			'timeout'            => 300,
			'reject_unsafe_urls' => true,
			'sslverify'          => ! $this->insecure_tls(),
			'redirection'        => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'mw_webdav_http_error',
				sprintf(
					/* translators: %d: HTTP status code returned by the WebDAV server. */
					__( 'پاسخ WebDAV ناموفق بود (کد %d).', 'music-wave-vip' ),
					$code
				)
			);
		}

		return array(
			'remote_path' => $remote_path,
			'checksum'    => null,
			'size'        => $size,
		);
	}

	public function delete( string $remote_path ): bool|WP_Error {
		$url = $this->url_for( $remote_path );
		if ( '' === $url ) {
			return new WP_Error( 'mw_webdav_endpoint_empty', __( 'انتهای WebDAV تنظیم نشده است.', 'music-wave-vip' ) );
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'             => 'DELETE',
				'timeout'            => 60,
				'reject_unsafe_urls' => true,
				'sslverify'          => ! $this->insecure_tls(),
				'redirection'        => 0,
			)
		); // phpcs:ignore WordPress.HTTP.HTTPRequest
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		return 200 === $code || 204 === $code;
	}

	public function test_connection(): array {
		$endpoint = $this->endpoint();
		if ( '' === $endpoint ) {
			return array(
				'ok'    => false,
				'error' => 'انتهای WebDAV تنظیم نشده است.',
			);
		}

		$response = wp_remote_request(
			$endpoint,
			array( // phpcs:ignore WordPress.HTTP.HTTPRequest
			'method'             => 'OPTIONS',
			'timeout'            => 15,
			'reject_unsafe_urls' => true,
			'sslverify'          => ! $this->insecure_tls(),
			'redirection'        => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'error' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			return array(
				'ok'    => false,
				'error' => 'پاسخ نامعتبر: ' . $code,
			);
		}

		return array(
			'ok'    => true,
			'error' => null,
		);
	}

	private function endpoint(): string {
		return rtrim( (string) ( $this->config['remote_upload_endpoint'] ?? '' ), '/' );
	}

	private function url_for( string $remote_path ): string {
		$endpoint = $this->endpoint();

		return '' === $endpoint ? '' : $endpoint . '/' . ltrim( $remote_path, '/' );
	}

	/**
	 * @return string|WP_Error
	 */
	private function read_file( string $local_path ) {
		$handle = fopen( $local_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new WP_Error( 'mw_webdav_cannot_open', __( 'فایل محلی قابل باز شدن نیست.', 'music-wave-vip' ) );
		}

		$data = '';
		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk ) {
				break;
			}
			$data .= $chunk;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $data;
	}

	/**
	 * Whether TLS verification is explicitly disabled (local testing only).
	 */
	private function insecure_tls(): bool {
		return 'enabled' === (string) ( $this->config['remote_upload_insecure_tls'] ?? 'disabled' );
	}

	private function __clone() {}
}
