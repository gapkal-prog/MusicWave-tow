<?php
/**
 * Recursive PHP syntax gate for every MusicWave package.
 *
 * Replaces the previous hand-maintained file list so new files can never be
 * silently excluded from the syntax check (PROJECT_PLAN.md Stage 0).
 *
 * Usage: php tools/check-syntax.php
 */

declare(strict_types=1);

$project_root = dirname( __DIR__ );
$targets      = array( 'music-wave-core', 'music-wave-vip', 'musicwave', 'tools', 'tests' );
$excluded     = array( 'vendor', 'node_modules', 'build', 'dist', '.staging' );

$files = array();
foreach ( $targets as $target ) {
	$base = $project_root . DIRECTORY_SEPARATOR . $target;
	if ( ! is_dir( $base ) ) {
		fwrite( STDERR, "Missing package directory: {$target}\n" );
		exit( 1 );
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
			static function ( SplFileInfo $current ) use ( $excluded ): bool {
				return ! ( $current->isDir() && in_array( $current->getFilename(), $excluded, true ) );
			}
		)
	);

	foreach ( $iterator as $file ) {
		if ( $file instanceof SplFileInfo && 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}
}

sort( $files );

if ( array() === $files ) {
	fwrite( STDERR, "No PHP files found; refusing to pass an empty gate.\n" );
	exit( 1 );
}

$failures = 0;

foreach ( $files as $file ) {
	$source = file_get_contents( $file );
	if ( false === $source ) {
		++$failures;
		fwrite( STDERR, "Unable to read PHP file: {$file}\n" );
		continue;
	}
	try {
		token_get_all( $source, TOKEN_PARSE );
	} catch ( ParseError $error ) {
		++$failures;
		fwrite( STDERR, "Parse error: {$error->getMessage()} in {$file} on line {$error->getLine()}\n" );
	}
}

$checked = count( $files );
if ( $failures > 0 ) {
	fwrite( STDERR, "Syntax check failed: {$failures} of {$checked} files contain errors.\n" );
	exit( 1 );
}

echo "Syntax check passed: {$checked} PHP files across " . implode( ', ', $targets ) . ".\n";
exit( 0 );
