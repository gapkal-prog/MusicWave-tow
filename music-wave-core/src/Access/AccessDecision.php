<?php
/**
 * Result of an access policy evaluation.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccessDecision {
	/** @var bool */
	private $allowed;

	/** @var string */
	private $reason;

	/** @var string */
	private $mode;

	private function __construct( bool $allowed, string $reason, string $mode ) {
		$this->allowed = $allowed;
		$this->reason  = $reason;
		$this->mode    = $mode;
	}

	public static function allow( string $reason, string $mode ): self {
		return new self( true, sanitize_key( $reason ), sanitize_key( $mode ) );
	}

	public static function deny( string $reason, string $mode = 'restricted' ): self {
		return new self( false, sanitize_key( $reason ), sanitize_key( $mode ) );
	}

	public function is_allowed(): bool {
		return $this->allowed;
	}

	public function reason(): string {
		return $this->reason;
	}

	public function mode(): string {
		return $this->mode;
	}
}
