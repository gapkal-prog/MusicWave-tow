<?php
/**
 * S3-compatible upload adapter (AWS S3, Cloudflare R2, Backblaze B2, MinIO,
 * Filebase, ...).
 *
 * Uses local SigV4 query-string signing — no heavy AWS SDK, suitable for shared
 * hosting. Files larger than `s3_multipart_threshold_mb` use Multipart Upload
 * (init → part upload → complete).
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
final class S3CompatibleUploadAdapter implements RemoteUploadAdapter {
	/** @var array<string, mixed> */
	private $config;
	/** @var string Signing region (or `auto` for R2). */
	private $region;

	/** @param array<string, mixed> $config Normalized VIP settings. */
	public function __construct( array $config ) {
		$this->config = $config;
		$this->region = '' !== (string) ( $config['remote_upload_region'] ?? '' ) ? (string) $config['remote_upload_region'] : 'auto';
	}

	public function name(): string {
		return 's3';
	}

	public function available(): bool {
		return function_exists( 'hash_hmac' ) && function_exists( 'wp_remote_request' );
	}

	/**
	 * An unreachable or missing object is reported as "absent", not as an
	 * error: dedupe only needs to know whether the bytes are already there.
	 *
	 * @return array{exists: bool, checksum: string|null, size: int|null}
	 */
	public function exists( string $remote_path, string $local_checksum, int $size ) {
		unset( $local_checksum );

		$url = $this->presigned_url( 'HEAD', $remote_path );
		if ( is_wp_error( $url ) ) {
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
			'checksum' => null, // S3 ETag is MD5, not SHA-256; compare by size instead.
			'size'     => '' !== $length ? absint( $length ) : null,
		);
	}

	public function upload( string $local_path, string $remote_path, int $size ) {
		if ( '' === (string) ( $this->config['remote_upload_bucket'] ?? '' ) ) {
			return new WP_Error( 'mw_s3_bucket_missing', __( 'باکت S3 تنظیم نشده است.', 'music-wave-vip' ) );
		}

		$threshold = max( 5, absint( $this->config['s3_multipart_threshold_mb'] ?? 100 ) );
		if ( $size > $threshold * 1048576 ) {
			return $this->multipart_upload( $local_path, $remote_path, $size );
		}

		return $this->single_put_upload( $local_path, $remote_path, $size );
	}

	public function delete( string $remote_path ): bool|WP_Error {
		$url = $this->presigned_url( 'DELETE', $remote_path );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$response = wp_remote_request(
			$url,
			array( // phpcs:ignore WordPress.HTTP.HTTPRequest
			'method'             => 'DELETE',
			'timeout'            => 60,
			'reject_unsafe_urls' => true,
			'sslverify'          => ! $this->insecure_tls(),
			'redirection'        => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		return 200 === $code || 204 === $code;
	}

	public function test_connection(): array {
		if ( '' === (string) ( $this->config['remote_upload_bucket'] ?? '' ) ) {
			return array(
				'ok'    => false,
				'error' => 'نام باکت تنظیم نشده است.',
			);
		}

		$url = $this->presigned_url( 'HEAD', '' );
		if ( is_wp_error( $url ) ) {
			return array(
				'ok'    => false,
				'error' => $url->get_error_message(),
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

	/*
	------------------------------------------------------------------
	 *  Transport                                                         *
	 * ------------------------------------------------------------------
	 */

	private function single_put_upload( string $local_path, string $remote_path, int $size ) {
		$url = $this->presigned_url( 'PUT', $remote_path, array(), array( 'x-amz-acl' => 'private' ) );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$data = $this->read_file( $local_path );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$response = wp_remote_request(
			$url,
			array( // phpcs:ignore WordPress.HTTP.HTTPRequest
			'method'             => 'PUT',
			'body'               => $data,
			'timeout'            => 300,
			'reject_unsafe_urls' => true,
			'sslverify'          => ! $this->insecure_tls(),
			'redirection'        => 0,
			'headers'            => array(
				'Content-Type' => 'application/octet-stream',
				'x-amz-acl'    => 'private',
			),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'mw_s3_http_error',
				sprintf(
					/* translators: %d: HTTP status code returned by the S3-compatible store. */
					__( 'پاسخ S3 ناموفق بود (کد %d).', 'music-wave-vip' ),
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

	/**
	 * Multipart upload for large files: init → upload parts → complete.
	 */
	private function multipart_upload( string $local_path, string $remote_path, int $size ) {
		$part_size  = 8 * 1048576; // 8 MiB parts.
		$part_count = (int) ceil( $size / $part_size );

		$init_url = $this->presigned_url( 'POST', $remote_path, array( 'uploads' => '' ) );
		if ( is_wp_error( $init_url ) ) {
			return $init_url;
		}
		$init_resp = wp_remote_post(
			$init_url,
			array( // phpcs:ignore WordPress.HTTP.HTTPRequest
			'timeout'            => 60,
			'reject_unsafe_urls' => true,
			'sslverify'          => ! $this->insecure_tls(),
			'redirection'        => 0,
			)
		);
		if ( is_wp_error( $init_resp ) ) {
			return $init_resp;
		}
		$upload_id = $this->extract_xml_tag( wp_remote_retrieve_body( $init_resp ), 'UploadId' );
		if ( '' === $upload_id ) {
			return new WP_Error( 'mw_s3_init_failed', __( 'شروع آپلود چندبخشی S3 ناموفق بود.', 'music-wave-vip' ) );
		}

		$handle = fopen( $local_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new WP_Error( 'mw_s3_cannot_open', __( 'فایل محلی قابل باز شدن نیست.', 'music-wave-vip' ) );
		}

		$etags = array();
		try {
			for ( $i = 1; $i <= $part_count; ++$i ) {
				$chunk = fread( $handle, $part_size ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $chunk || '' === $chunk ) {
					break;
				}

				$part_url = $this->presigned_url(
					'PUT',
					$remote_path,
					array(
						'partNumber' => (string) $i,
						'uploadId'   => $upload_id,
					)
				);
				if ( is_wp_error( $part_url ) ) {
					return $part_url;
				}

				$part_resp = wp_remote_request(
					$part_url,
					array( // phpcs:ignore WordPress.HTTP.HTTPRequest
					'method'             => 'PUT',
					'body'               => $chunk,
					'timeout'            => 300,
					'reject_unsafe_urls' => true,
					'sslverify'          => ! $this->insecure_tls(),
					'redirection'        => 0,
					)
				);
				if ( is_wp_error( $part_resp ) ) {
					return $part_resp;
				}
				$code = wp_remote_retrieve_response_code( $part_resp );
				if ( $code < 200 || $code >= 300 ) {
					return new WP_Error(
						'mw_s3_part_error',
						sprintf(
							/* translators: 1: part number, 2: HTTP status code. */
							__( 'بخش %1$d با کد %2$d رد شد.', 'music-wave-vip' ),
							$i,
							$code
						)
					);
				}
				$etags[] = (string) wp_remote_retrieve_header( $part_resp, 'ETag' );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$xml = '<CompleteMultipartUpload>';
		foreach ( $etags as $index => $etag ) {
			$xml .= '<Part><PartNumber>' . ( $index + 1 ) . '</PartNumber><ETag>' . $this->xml_escape( $etag ) . '</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';

		$complete_url = $this->presigned_url( 'POST', $remote_path, array( 'uploadId' => $upload_id ) );
		if ( is_wp_error( $complete_url ) ) {
			return $complete_url;
		}
		$complete_resp = wp_remote_post(
			$complete_url,
			array( // phpcs:ignore WordPress.HTTP.HTTPRequest
			'body'               => $xml,
			'timeout'            => 120,
			'reject_unsafe_urls' => true,
			'sslverify'          => ! $this->insecure_tls(),
			'redirection'        => 0,
			'headers'            => array( 'Content-Type' => 'application/xml' ),
			)
		);
		if ( is_wp_error( $complete_resp ) ) {
			return $complete_resp;
		}
		$code = wp_remote_retrieve_response_code( $complete_resp );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'mw_s3_complete_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'تکمیل آپلود چندبخشی با کد %d رد شد.', 'music-wave-vip' ),
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

	/**
	 * Build a SigV4 query-signed URL (path-style addressing).
	 *
	 * @param string               $method        HTTP verb.
	 * @param string               $key           Object key (`''` for bucket root).
	 * @param array<string,string> $query         Extra query parameters (sorted into sign).
	 * @param array<string,string> $extra_headers Headers to include in the signature.
	 * @return string|WP_Error
	 */
	private function presigned_url( string $method, string $key, array $query = array(), array $extra_headers = array(), int $ttl = 300 ) {
		$endpoint = rtrim( (string) ( $this->config['remote_upload_endpoint'] ?? '' ), '/' );
		$bucket   = (string) ( $this->config['remote_upload_bucket'] ?? '' );
		$access   = (string) ( $this->config['remote_upload_access_key'] ?? '' );
		$secret   = (string) ( $this->config['remote_upload_secret_key'] ?? '' );

		if ( '' === $endpoint || '' === $bucket || '' === $access || '' === $secret ) {
			return new WP_Error( 'mw_s3_credentials_missing', __( 'اعتبارنامه S3 ناقص است.', 'music-wave-vip' ) );
		}

		$host      = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
		$scheme    = (string) wp_parse_url( $endpoint, PHP_URL_SCHEME );
		$port      = wp_parse_url( $endpoint, PHP_URL_PORT );
		$base_path = (string) wp_parse_url( $endpoint, PHP_URL_PATH );
		$now       = gmdate( 'Ymd\THis\Z' );
		$date      = substr( $now, 0, 8 );
		$scope     = $date . '/' . $this->region . '/s3/aws4_request';

		$headers = array( 'host' => '' !== (string) $port ? $host . ':' . (string) $port : $host );
		foreach ( $extra_headers as $name => $value ) {
			$headers[ strtolower( $name ) ] = (string) $value;
		}
		ksort( $headers );

		$signed_headers = implode( ';', array_keys( $headers ) );

		$params = array_merge(
			array(
				'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
				'X-Amz-Credential'    => $access . '/' . $scope,
				'X-Amz-Date'          => $now,
				'X-Amz-Expires'       => (string) $ttl,
				'X-Amz-SignedHeaders' => $signed_headers,
			),
			$query
		);
		ksort( $params );

		$pairs = array();
		foreach ( $params as $name => $value ) {
			$pairs[] = rawurlencode( (string) $name ) . '=' . rawurlencode( (string) $value );
		}
		$canonical_query = implode( '&', $pairs );

		// The canonical URI must equal the path actually requested, otherwise the
		// signature does not match. Build it once and reuse it for the URL.
		$canonical_uri = rtrim( $base_path, '/' ) . '/' . $this->encode_key( $bucket );
		if ( '' !== $key ) {
			$canonical_uri .= '/' . $this->encode_key( $key );
		}

		$canonical_headers = '';
		foreach ( $headers as $name => $value ) {
			$canonical_headers .= $name . ':' . $value . "\n";
		}

		$canonical_request = $method . "\n"
			. $canonical_uri . "\n"
			. $canonical_query . "\n"
			. $canonical_headers . "\n"
			. $signed_headers . "\n"
			. 'UNSIGNED-PAYLOAD';

		$string_to_sign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );

		$signing_key = hash_hmac( 'sha256', 'aws4_request', hash_hmac( 'sha256', 's3', hash_hmac( 'sha256', $this->region, hash_hmac( 'sha256', $date, 'AWS4' . $secret, true ), true ), true ), true );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$authority = $scheme . '://' . $host . ( '' !== (string) $port ? ':' . (string) $port : '' );

		return $authority . $canonical_uri . '?' . $canonical_query . '&X-Amz-Signature=' . $signature;
	}

	/**
	 * Percent-encode each path segment of an object key, preserving slashes.
	 */
	private function encode_key( string $key ): string {
		if ( '' === $key ) {
			return '';
		}

		return implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
	}

	/**
	 * @return string|WP_Error
	 */
	private function read_file( string $local_path ) {
		$handle = fopen( $local_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new WP_Error( 'mw_s3_cannot_open', __( 'فایل محلی قابل باز شدن نیست.', 'music-wave-vip' ) );
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

	private function extract_xml_tag( string $xml, string $tag ): string {
		if ( 1 !== preg_match( '/<' . $tag . '>([^<]+)<\/' . $tag . '>/', $xml, $m ) ) {
			return '';
		}

		return (string) $m[1];
	}

	private function xml_escape( string $value ): string {
		return str_replace( array( '&', '<', '>', '"', "'" ), array( '&amp;', '&lt;', '&gt;', '&quot;', '&#039;' ), $value );
	}

	/**
	 * Whether TLS verification is explicitly disabled (local testing only).
	 */
	private function insecure_tls(): bool {
		return 'enabled' === (string) ( $this->config['remote_upload_insecure_tls'] ?? 'disabled' );
	}

	private function __clone() {}
}
