<?php
/**
 * PHPStan stub for the WordPress schema upgrade API.
 *
 * @package ManaCore\MusicWave\Tools
 */

declare(strict_types=1);

if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * Modify the database based on specified SQL statements.
	 *
	 * @param string|array<int, string> $queries Schema queries.
	 * @param bool                      $execute Whether to execute.
	 * @return array<string, string>
	 */
	function dbDelta( $queries = '', $execute = true ) {
		unset( $queries, $execute );
		return array();
	}
}
