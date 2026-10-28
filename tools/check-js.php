<?php
/**
 * Soft JavaScript quality gate.
 *
 * Runs the npm lint script when the Node toolchain is installed and skips
 * cleanly when it is not, so the PHP quality gates never depend on npm.
 *
 * Usage: php tools/check-js.php
 */

declare(strict_types=1);

$project_root = dirname( __DIR__ );

if ( ! is_dir( $project_root . '/node_modules' ) ) {
	echo "Skipped JavaScript lint: run `npm install` first to enable the JS quality gate.\n";
	exit( 0 );
}

passthru( 'npm run lint:js', $music_wave_exit_code );
exit( is_int( $music_wave_exit_code ) ? $music_wave_exit_code : 1 );
