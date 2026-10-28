<?php
/**
 * Abstraction for outbound HTTP.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface HttpClientInterface {
	/**
	 * Perform a GET request.
	 *
	 * @param string               $url  Absolute URL.
	 * @param array<string, mixed> $args WP HTTP args (timeout, headers, user-agent, etc).
	 */
	public function get( string $url, array $args = array() ): HttpResponse;

	/**
	 * Perform a POST request.
	 *
	 * @param string               $url  Absolute URL.
	 * @param array<string, mixed> $args WP HTTP args.
	 */
	public function post( string $url, array $args = array() ): HttpResponse;
}
