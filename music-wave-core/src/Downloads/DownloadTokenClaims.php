<?php
/**
 * Immutable validated download/stream token claims value object.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DownloadTokenClaims {
	/** @var int */
	private $release_id;
	/** @var int */ private $user_id;
	/** @var int */ private $expires_at;
	/** @var string */ private $token_id;
	/** @var string */ private $binding;
	/** @var string */ private $asset_key;
	/** @var string */ private $purpose;

	public function __construct( int $release_id, int $user_id, int $expires_at, string $token_id, string $binding, string $asset_key = 'standard', string $purpose = 'download' ) {
		$this->release_id = $release_id;
		$this->user_id    = $user_id;
		$this->expires_at = $expires_at;
		$this->token_id   = $token_id;
		$this->binding    = $binding;
		$this->asset_key  = $asset_key;
		$this->purpose    = $purpose;
	}
	public function release_id(): int {
		return $this->release_id; }
	public function user_id(): int {
		return $this->user_id; }
	public function expires_at(): int {
		return $this->expires_at; }
	public function token_id(): string {
		return $this->token_id; }
	public function binding(): string {
		return $this->binding; }
	public function asset_key(): string {
		return $this->asset_key; }
	public function purpose(): string {
		return $this->purpose; }
}
