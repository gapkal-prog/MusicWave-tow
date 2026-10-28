<?php
/**
 * Value object for HTTP responses.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HttpResponse {
	/** @var int */
	private $status;

	/** @var string */
	private $body;

	/** @var array<string, string> */
	private $headers;

	/** @var \WP_Error|null */
	private $error;

	/**
	 * @param int                     $status  HTTP status code (0 when WP_Error).
	 * @param string                  $body    Response body.
	 * @param array<string, string>   $headers Normalized headers.
	 * @param \WP_Error|null          $error   Underlying WP_Error if any.
	 */
	public function __construct( int $status, string $body, array $headers = array(), ?\WP_Error $error = null ) {
		$this->status  = $status;
		$this->body    = $body;
		$this->headers = $headers;
		$this->error   = $error;
	}

	public function status(): int {
		return $this->status;
	}

	public function body(): string {
		return $this->body;
	}

	/**
	 * @return array<string, string>
	 */
	public function headers(): array {
		return $this->headers;
	}

	public function error(): ?\WP_Error {
		return $this->error;
	}

	public function is_error(): bool {
		return null !== $this->error;
	}

	public function is_success(): bool {
		return null === $this->error && $this->status >= 200 && $this->status < 300;
	}

	public function is_rate_limited(): bool {
		return 429 === $this->status || 503 === $this->status;
	}

	public function is_not_found(): bool {
		return 404 === $this->status;
	}

	/**
	 * Decode JSON body.
	 *
	 * @return array<string, mixed>|null
	 */
	public function json(): ?array {
		$data = json_decode( $this->body, true );
		return is_array( $data ) ? $data : null;
	}
}
