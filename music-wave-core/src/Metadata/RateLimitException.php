<?php
/**
 * Raised when a metadata provider throttles or depletes its quota.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RateLimitException extends \RuntimeException {}
