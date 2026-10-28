<?php
/**
 * Minimal dependency-free PSR-4 autoloader.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Autoloader {
	private const PREFIX = 'ManaCore\\MusicWave\\Core\\';

	/** @var bool */
	private static $registered = false;

	/**
	 * Register the project autoloader.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		spl_autoload_register( array( self::class, 'load' ) );
		self::$registered = true;
	}

	/**
	 * Load a class within the MusicWave Core namespace.
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return void
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( self::PREFIX ) );
		$file           = dirname( __DIR__ ) . DIRECTORY_SEPARATOR
			. str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
