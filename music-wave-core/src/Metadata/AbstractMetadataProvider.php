<?php
/**
 * Base provider with shared HTTP and JSON handling.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata;

use ManaCore\MusicWave\Core\Metadata\Http\HttpClientInterface;
use ManaCore\MusicWave\Core\Metadata\Http\HttpResponse;
use ManaCore\MusicWave\Core\Metadata\Http\WpHttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AbstractMetadataProvider implements MetadataProvider {
	/** @var HttpClientInterface */
	protected $http;

	public function __construct( ?HttpClientInterface $http = null ) {
		$this->http = $http ?? new WpHttpClient();
	}

	/**
	 * Decode JSON with consistent error handling.
	 *
	 * @throws RateLimitException
	 * @throws \RuntimeException
	 * @return array<string, mixed>|null
	 */
	protected function decode_json( HttpResponse $response, bool $allow_not_found = false ): ?array {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal provider/HTTP status diagnostic message, escaped at UI boundary.
		if ( $response->is_error() ) {
			throw new \RuntimeException( $this->name() . ' connection failed.' );
		}

		if ( $response->is_rate_limited() ) {
			throw new RateLimitException( $this->name() . ' rate limit reached.' );
		}

		if ( $allow_not_found && $response->is_not_found() ) {
			return null;
		}

		if ( ! $response->is_success() ) {
			throw new \RuntimeException( $this->name() . ' HTTP request failed with status ' . $response->status() . '.' );
		}

		$data = $response->json();
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( $this->name() . ' returned invalid JSON.' );
		}
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

		return $data;
	}

	/**
	 * Helper to build a safe URL with query args.
	 *
	 * @param array<string, mixed> $args
	 */
	protected function build_url( string $base, array $args ): string {
		return add_query_arg( $args, $base );
	}
}
