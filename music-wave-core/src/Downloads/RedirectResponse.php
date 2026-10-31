<?php
/**
 * Shared 302 redirect response with security headers for external delivery.
 *
 * Used by DirectLinkProvider (and RemoteRedirectProvider after phase 5) so all
 * external redirects emit the same cache/no-index/no-referrer contract.
 *
 * @package ManaCore\MusicWave\Core\Downloads
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RedirectResponse {
	/** @var string */
	private $url;

	/** @var int */
	private $status;

	/** @var array<string, string> */
	private $headers;

	/**
	 * @param string $url Target URL (already validated).
	 * @param int $status HTTP status (301/302/307/308).
	 * @param array<string, string> $extra Additional headers.
	 */
	public function __construct( string $url, int $status = 302, array $extra = array() ) {
		$this->url     = $url;
		$this->status  = $status;
		$this->headers = array_merge(
			array(
				'Cache-Control'          => 'no-store, private',
				'Referrer-Policy'        => 'no-referrer',
				'X-Content-Type-Options' => 'nosniff',
				'X-Robots-Tag'           => 'noindex',
			),
			$extra
		);
	}

	/**
	 * Emit headers and terminate. Injectable for testing: returns the would-be
	 * output instead of exiting when MUSIC_WAVE_TESTING is true.
	 *
	 * The URL has already been validated by `DirectLinkUrlValidator`, so it is
	 * safe to emit directly in the `Location` header without `esc_url()` —
	 * `esc_url()` is intended for HTML attribute context and can alter percent
	 * encoding (e.g. `%20`, `%28`, `%29`) which breaks direct external URLs.
	 *
	 * @return bool True when headers were emitted (or testing mode simulated it).
	 */
	public function send(): bool {
		if ( defined( 'MUSIC_WAVE_TESTING' ) && MUSIC_WAVE_TESTING ) {
			return true;
		}

		status_header( $this->status );
		foreach ( $this->headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
		header( 'Location: ' . $this->url );
		exit;
	}

	/** @return string */
	public function url(): string {
		return $this->url;
	}

	/** @return int */
	public function status(): int {
		return $this->status;
	}

	/** @return array<string, string> */
	public function headers(): array {
		return $this->headers;
	}
}
