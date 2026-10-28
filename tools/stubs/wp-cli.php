<?php
/**
 * PHPStan stub for the WP-CLI runtime.
 *
 * @package ManaCore\MusicWave\Tools
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal WP-CLI surface used by MusicWave commands.
	 */
	class WP_CLI {
		/**
		 * @param string $name     Command name.
		 * @param mixed  $callable Command implementation.
		 * @return void
		 */
		public static function add_command( string $name, $callable ): void {
			unset( $name, $callable );
		}

		public static function success( string $message ): void {
			unset( $message );
		}

		public static function error( string $message ): void {
			unset( $message );
		}

		public static function log( string $message ): void {
			unset( $message );
		}
	}
}
