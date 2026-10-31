<?php
/**
 * Edge/CDN connectivity and origin-header validation for edge-signed delivery.
 *
 * Provides a connection test for the admin UI and validates that the origin
 * hides its real address behind a shared secret header.
 *
 * @package ManaCore\MusicWave\Vip\Delivery
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Delivery;

use ManaCore\MusicWave\Core\Downloads\DirectLinkUrlValidator;
use ManaCore\MusicWave\Vip\VipSettings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @immutable
 */
final class EdgeConnectionManager {
	/** Reserved headers that must never be used as the origin token name. */
	public const RESERVED_HEADER_NAMES = array(
		'host',
		'authorization',
		'cookie',
		'content-length',
		'x-forwarded-for',
		'x-forwarded-host',
		'x-forwarded-proto',
		'x-forwarded-port',
	);

	/** @var array<string, mixed> */
	private $config;

	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Run a connection test against the edge base URL.
	 *
	 * @return array{ok: bool, status: int|null, error: string|null, details: array<string, mixed>}
	 */
	public function test(): array {
		$config     = $this->config;
		$base_url   = isset( $config['edge_base_url'] ) ? trim( (string) $config['edge_base_url'] ) : '';
		$secret     = isset( $config['edge_signing_secret'] ) ? (string) $config['edge_signing_secret'] : '';
		$header     = isset( $config['edge_origin_header_name'] ) ? (string) $config['edge_origin_header_name'] : '';
		$header_val = isset( $config['edge_origin_header_value'] ) ? (string) $config['edge_origin_header_value'] : '';

		$details = array(
			'base_url'          => $base_url,
			'https'             => 'https' === wp_parse_url( $base_url, PHP_URL_SCHEME ),
			'secret_strong'     => strlen( $secret ) >= 32,
			'header_name_valid' => $this->valid_header_name( $header ),
			'header_value_set'  => '' !== $header_val,
		);

		$errors = array();
		if ( '' === $base_url ) {
			$errors[] = 'آدرس پایهٔ لبه تنظیم نشده است.';
		}
		if ( ! $details['https'] ) {
			$errors[] = 'آدرس لبه باید HTTPS باشد.';
		}
		if ( ! $details['secret_strong'] ) {
			$errors[] = 'کلید مخفی امضای لبه باید حداقل ۳۲ کاراکتر باشد.';
		}
		if ( ! $details['header_name_valid'] ) {
			$errors[] = 'نام هدر مبدأ نامعتبر یا رزروشده است.';
		}
		if ( ! $details['header_value_set'] ) {
			$errors[] = 'مقدار هدر مبدأ تنظیم نشده است.';
		}

		$ok = 0 === count( $errors );

		// Attempt a lightweight HEAD/GET when everything is configured.
		$status      = null;
		$error_probe = '';
		if ( $ok && '' !== $base_url ) {
			$status = $this->probe_endpoint( $base_url, $header, $header_val, $error_probe );
			if ( '' !== $error_probe ) {
				$details['probe_error'] = $error_probe;
			}
		}

		return array(
			'ok'      => $ok,
			'status'  => $status,
			'error'   => $ok ? null : implode( ' ', $errors ),
			'details' => $details,
		);
	}

	/**
	 * Validate the origin header name against reserved/unsafe names.
	 */
	public function valid_header_name( string $name ): bool {
		if ( '' === $name || strlen( $name ) < 3 ) {
			return false;
		}
		$lower = strtolower( trim( $name ) );
		if ( in_array( $lower, self::RESERVED_HEADER_NAMES, true ) ) {
			return false;
		}
		// Header names must be tokens: no spaces, colons, or control chars.
		return 1 === preg_match( '/^[A-Za-z][A-Za-z0-9\-]*$/', $lower );
	}

	/**
	 * Build origin header config for the edge pull configuration.
	 *
	 * @return array{name: string, value: string}|array{name: string, value: string, error: string}
	 */
	public function origin_header_config(): array {
		$name  = isset( $this->config['edge_origin_header_name'] ) ? (string) $this->config['edge_origin_header_name'] : '';
		$value = isset( $this->config['edge_origin_header_value'] ) ? (string) $this->config['edge_origin_header_value'] : '';

		if ( '' === $name || '' === $value || ! $this->valid_header_name( $name ) ) {
			return array(
				'name'  => $name,
				'value' => $value,
				'error' => 'نام یا مقدار هدر مبدأ نامعتبر است.',
			);
		}

		return array(
			'name'  => $name,
			'value' => $value,
		);
	}

	/**
	 * Probe the edge endpoint (best effort; WP may block outbound requests).
	 */
	private function probe_endpoint( string $url, string $header, string $header_value, string &$error_out = '' ): ?int {
		$error_out = '';
		if ( ! function_exists( 'wp_remote_get' ) ) {
			$error_out = 'تابع wp_remote_get در دسترس نیست.';
			return null;
		}

		$response = wp_remote_get(
			$url,
			array( // phpcs:ignore WordPress.HTTP.HTTPRequest -- admin-initiated connectivity probe.
			'method'             => 'HEAD',
			'timeout'            => 10,
			'redirection'        => 0,
			'headers'            => $header ? array( $header => $header_value ) : array(),
			'reject_unsafe_urls' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_out = $response->get_error_message();
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		return is_int( $code ) ? $code : null;
	}

	private function __clone() {}
}
