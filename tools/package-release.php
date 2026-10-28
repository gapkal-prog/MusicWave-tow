<?php
/**
 * Create auditable MusicWave distribution archives.
 *
 * Usage: php tools/package-release.php --destination=dist
 */

declare(strict_types=1);

$options = getopt( '', array( 'destination:' ) );
if ( ! isset( $options['destination'] ) || ! is_string( $options['destination'] ) || '' === trim( $options['destination'] ) ) {
	fwrite( STDERR, "Usage: php tools/package-release.php --destination=path\n" );
	exit( 1 );
}
if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The PHP ZipArchive extension is required to package a release.\n" );
	exit( 1 );
}

$project_root = dirname( __DIR__ );
$destination  = $options['destination'];
if ( ! is_dir( $destination ) && ! mkdir( $destination, 0775, true ) && ! is_dir( $destination ) ) {
	fwrite( STDERR, "Could not create destination directory: {$destination}\n" );
	exit( 1 );
}
$destination = realpath( $destination );
if ( false === $destination ) {
	fwrite( STDERR, "Could not resolve destination directory.\n" );
	exit( 1 );
}

$components = array(
	'musicwave'       => 'style.css',
	'music-wave-core' => 'music-wave-core.php',
	'music-wave-vip'  => 'music-wave-vip.php',
);
$checksums  = array();

foreach ( $components as $component => $entry_file ) {
	$source = $project_root . DIRECTORY_SEPARATOR . $component;
	if ( ! is_file( $source . DIRECTORY_SEPARATOR . $entry_file ) ) {
		fwrite( STDERR, "Missing distribution entry file: {$component}/{$entry_file}\n" );
		exit( 1 );
	}

	$archive_path = $destination . DIRECTORY_SEPARATOR . $component . '.zip';
	$zip          = new ZipArchive();
	if ( true !== $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		fwrite( STDERR, "Could not create archive: {$archive_path}\n" );
		exit( 1 );
	}

	$files = musicwave_release_files( $source );
	if ( ! isset( $files['LICENSE'] ) ) {
		$license = $project_root . '/LICENSE';
		if ( ! is_readable( $license ) ) {
			fwrite( STDERR, "Missing product license text.\n" );
			exit( 1 );
		}
		$files['LICENSE'] = $license;
	}
	// Include root CHANGELOG.md for buyer reference (FIX-06, FIX-38)
	$changelog = $project_root . '/CHANGELOG.md';
	if ( is_readable( $changelog ) && ! isset( $files['CHANGELOG.md'] ) ) {
		$files['CHANGELOG.md'] = $changelog;
	}
	// Validate required files for theme (FIX-38)
	$missing = musicwave_release_required_files( $component, $files );
	if ( array() !== $missing ) {
		fwrite( STDERR, "Missing required files in {$component}: " . implode( ', ', $missing ) . "\n" );
		exit( 1 );
	}
	ksort( $files, SORT_STRING );
	$manifest = array();
	foreach ( $files as $relative_path => $absolute_path ) {
		$archive_name = $component . '/' . $relative_path;
		if ( ! $zip->addFile( $absolute_path, $archive_name ) ) {
			$zip->close();
			fwrite( STDERR, "Could not add file to archive: {$archive_name}\n" );
			exit( 1 );
		}
		$manifest[] = hash_file( 'sha256', $absolute_path ) . '  ' . $relative_path;
	}

	$zip->addFromString( $component . '/MANIFEST.sha256', implode( "\n", $manifest ) . "\n" );
	$zip->close();
	$checksums[] = hash_file( 'sha256', $archive_path ) . '  ' . basename( $archive_path );
	echo "Created {$archive_path}\n";
}

file_put_contents( $destination . DIRECTORY_SEPARATOR . 'checksums.sha256', implode( "\n", $checksums ) . "\n" );
echo "Created {$destination}" . DIRECTORY_SEPARATOR . "checksums.sha256\n";

/**
 * Return an ordered, filtered source file map.
 *
 * @return array<string, string>
 */
function musicwave_release_files( string $source ): array {
	$files    = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	/** @var SplFileInfo $file */
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || $file->isLink() ) {
			continue;
		}

		$absolute = $file->getRealPath();
		if ( false === $absolute ) {
			continue;
		}
		$relative = ltrim( substr( $absolute, strlen( rtrim( $source, DIRECTORY_SEPARATOR ) ) ), DIRECTORY_SEPARATOR );
		$relative = str_replace( DIRECTORY_SEPARATOR, '/', $relative );
		if ( musicwave_release_excluded( $relative ) ) {
			continue;
		}
		$files[ $relative ] = $absolute;
	}

	ksort( $files, SORT_STRING );

	return $files;
}

function musicwave_release_excluded( string $relative ): bool {
	$segments = explode( '/', $relative );
	$blocked  = array( '.git', '.github', '.staging', 'node_modules', 'vendor', 'tests', 'tools' );
	foreach ( $segments as $segment ) {
		if ( in_array( $segment, $blocked, true ) ) {
			return true;
		}
	}

	// Design mocks live at a component root; the block markup a theme actually
	// ships lives in templates/ and parts/, so only depth-1 HTML is dropped.
	if ( 1 === preg_match( '#^[^/]+\.html$#', $relative ) ) {
		return true;
	}

	$filename = basename( $relative );

	// Exclude audit markdowns and any docs/audits if accidentally inside component (FIX-38)
	if ( 0 === strpos( $relative, 'docs/audits/' ) || 0 === strpos( $relative, 'audit-' ) || false !== strpos( $relative, '/audit-' ) ) {
		return true;
	}

	// Never hash the previous manifest into its regenerated replacement.
	return 'MANIFEST.sha256' === $filename || '.DS_Store' === $filename || 'Thumbs.db' === $filename || '.gitignore' === $filename || '.gitkeep' === $filename || 1 === preg_match( '/\.(log|map)$/', $filename );
}

function musicwave_release_required_files( string $component, array $files ): array {
	$required = array();
	if ( 'musicwave' === $component ) {
		$required = array( 'screenshot.png', 'style.css', 'assets/fonts/OFL.txt', 'assets/fonts/OFL-PlusJakartaSans.txt', 'assets/fonts/OFL-Syne.txt' );
	}
	$missing = array();
	foreach ( $required as $req ) {
		if ( ! isset( $files[ $req ] ) ) {
			$missing[] = $req;
		}
	}
	return $missing;
}
