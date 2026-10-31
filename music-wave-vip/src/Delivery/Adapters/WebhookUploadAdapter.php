<?php
/**
 * Generic webhook upload adapter: PUT-streams the file to a single URL the
 * admin provides (the "one upload link" model).
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

final class WebhookUploadAdapter implements RemoteUploadAdapter {
	/** @var array<string, mixed> */
	private $config;

	/** @param array<string, mixed> $config Normalized VIP settings. */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	public function name(): string {
		return 'webhook';
	}

	public function available(): bool {
		return function_exists( 'wp_remote_request' );
	}

	/**
	 * A generic webhook has no existence probe, so any failure to confirm is
	 * reported as "absent" rather than as an error.
	 *
	 * @return array{exists: bool, checksum: string|null, size: int|null}
	 */
	public function exists( string $remote_path, string $local_checksum, int $size ) {
		unset( $remote_path, $local_checksum, $size );

		// A generic webhook has no standard existence probe; report "absent" so
		// the upload proceeds and the queue's stored state provides dedupe.
		return array(
			'exists'   => false,
			'checksum' => null,
			'size'     => null,
		);
	}

	public function upload( string $local_path, string $remote_path, int $size ) {
		$endpoint = trim( (string) ( $this->config['remote_upload_endpoint'] ?? '' ) );
		if ( '' === $endpoint ) {
			return new WP_Error( 'mw_upload_endpoint_empty', __( 'انتهای آپلود تنظیم نشده است.', 'music-wave-vip' ) );
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
			$endpoint,
			array( // phpcs:ignore WordPress.HTTP.HTTPRequest -- admin-triggered upload to a user-configured endpoint.
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
				'mw_upload_http_error',
				sprintf(
					/* translators: %d: HTTP status code returned by the download server. */
					__( 'سرور دانلود پاسخ ناموفق داد (کد %d).', 'music-wave-vip' ),
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
		unset( $remote_path );

		// The single-link webhook model exposes no reliable object deletion.
		return true;
	}

	public function test_connection(): array {
		$endpoint = trim( (string) ( $this->config['remote_upload_endpoint'] ?? '' ) );
		if ( '' === $endpoint ) {
			return array(
				'ok'    => false,
				'error' => 'انتهای آپلود تنظیم نشده است.',
			);
		}
		if ( ! function_exists( 'wp_remote_request' ) ) {
			return array(
				'ok'    => false,
				'error' => 'تابع wp_remote_request در دسترس نیست.',
			);
		}

		$response = wp_remote_request(
			$endpoint,
			array( // phpcs:ignore WordPress.HTTP.HTTPRequest
			'method'             => 'HEAD',
			'timeout'            => 10,
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

	/**
	 * @return string|WP_Error
	 */
	private function read_file( string $local_path ) {
		$handle = fopen( $local_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new WP_Error( 'mw_upload_cannot_open', __( 'فایل محلی قابل باز شدن نیست.', 'music-wave-vip' ) );
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
