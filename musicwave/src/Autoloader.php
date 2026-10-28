<?php
declare(strict_types=1);

namespace ManaCore\MusicWave\Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PSR-4 autoloader for the MusicWave block theme namespace.
 */
final class Autoloader {

	private const PREFIX = 'ManaCore\\MusicWave\\Theme\\';

	/**
	 * Whether the theme PSR-4 autoloader has already been registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Register the PSR-4 autoloader against the theme's src directory.
	 */
	public static function register( string $base_dir ): void {
		if ( self::$registered ) {
			return;
		}

		$normalized_base = rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR;

		spl_autoload_register(
			static function ( string $class_name ) use ( $normalized_base ): void {
				if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
					return;
				}

				$relative = substr( $class_name, strlen( self::PREFIX ) );
				$file     = $normalized_base . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);

		self::$registered = true;
	}
}
