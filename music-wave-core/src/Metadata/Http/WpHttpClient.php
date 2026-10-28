<?php
/**
 * WordPress HTTP client implementing HttpClientInterface.
 *
 * Centralizes user-agent, timeout handling and WP_Error mapping.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WpHttpClient implements HttpClientInterface {
	private const DEFAULT_TIMEOUT = 10;
	private const DEFAULT_UA      = 'MusicWave/%s ( %s )';

	/** @var string */
	private $user_agent;

	public function __construct( string $user_agent = '' ) {
		if ( '' !== $user_agent ) {
			$this->user_agent = $user_agent;
		} else {
			$version          = defined( 'MUSIC_WAVE_CORE_VERSION' ) ? MUSIC_WAVE_CORE_VERSION : '1.0';
			$home             = function_exists( 'home_url' ) ? home_url( '/' ) : '';
			$this->user_agent = sprintf( self::DEFAULT_UA, $version, $home );
		}
	}

	public function get( string $url, array $args = array() ): HttpResponse {
		$args = $this->prepare_args( $args );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- safe wrapper used via wp_safe_remote_get
		$response = wp_safe_remote_get( $url, $args );

		return $this->to_response( $response );
	}

	public function post( string $url, array $args = array() ): HttpResponse {
		$args     = $this->prepare_args( $args );
		$response = wp_safe_remote_post( $url, $args );

		return $this->to_response( $response );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function prepare_args( array $args ): array {
		$defaults = array(
			'timeout'    => self::DEFAULT_TIMEOUT,
			'user-agent' => $this->user_agent,
		);

		if ( isset( $args['headers'] ) && ! is_array( $args['headers'] ) ) {
			unset( $args['headers'] );
		}

		return array_merge( $defaults, $args );
	}

	/**
	 * @param array<string, mixed>|\WP_Error $raw
	 */
	private function to_response( $raw ): HttpResponse {
		if ( is_wp_error( $raw ) ) {
			return new HttpResponse( 0, '', array(), $raw );
		}

		$status      = (int) wp_remote_retrieve_response_code( $raw );
		$body        = (string) wp_remote_retrieve_body( $raw );
		$headers     = array();
		$raw_headers = wp_remote_retrieve_headers( $raw );
		if ( is_array( $raw_headers ) ) {
			foreach ( $raw_headers as $k => $v ) {
				if ( is_string( $k ) && is_scalar( $v ) ) {
					$headers[ $k ] = (string) $v;
				}
			}
		} elseif ( is_object( $raw_headers ) && method_exists( $raw_headers, 'getAll' ) ) {
			foreach ( $raw_headers->getAll() as $k => $v ) {
				if ( is_string( $k ) && is_scalar( $v ) ) {
					$headers[ $k ] = (string) $v;
				}
			}
		}

		return new HttpResponse( $status, $body, $headers, null );
	}
}
