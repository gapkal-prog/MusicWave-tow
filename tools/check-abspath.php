<?php
/**
 * Check ABSPATH guards and index.php silence files.
 * Fails when shipped PHP lacks guard or shipped dir lacks index.php.
 * Patterns directory is exception for index.php (WordPress registers every PHP file there as pattern).
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Allow CLI run without WP, but guard for direct web access.
	if ( php_sapi_name() !== 'cli' && ! defined( 'WP_CLI' ) ) {
		exit;
	}
}

// Normalise separators so the POSIX-style exclusions and relative paths below
// keep matching on Windows, where getPathname() returns backslashes.
$root = str_replace( '\\', '/', dirname( __DIR__ ) );
$dirs = ['musicwave', 'music-wave-core', 'music-wave-vip'];
$exclude_substrings = ['/vendor/', '/node_modules/', '/tests/', '/tools/', '/languages/'];
$exclude_dir_names = ['vendor', 'node_modules', 'tests', 'tools', 'languages'];

$errors = [];

// Check PHP files for ABSPATH guard
$php_files = [];
$checked_php_files = 0;
foreach ($dirs as $dir) {
	$full = $root . '/' . $dir;
	if (!is_dir($full)) continue;
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $file) {
		/** @var SplFileInfo $file */
		if ($file->getExtension() !== 'php') continue;
		$path = str_replace( '\\', '/', $file->getPathname() );
		$skip = false;
		foreach ($exclude_substrings as $ex) {
			if (strpos($path, $ex) !== false) { $skip = true; break; }
		}
		if ($skip) continue;
		// index.php silence files are allowed to have only guard or silence - they already have ABSPATH after fix, but check still
		$content = file_get_contents($path);
		if ($content === false) continue;
		++$checked_php_files;
		// Must contain ABSPATH
		if (false === strpos($content, 'ABSPATH')) {
			$php_files[] = str_replace($root . '/', '', $path);
		}
	}
}

if (!empty($php_files)) {
	$errors[] = "PHP files missing ABSPATH guard (" . count($php_files) . "):\n - " . implode("\n - ", $php_files);
}

// Check directories for index.php
$all_dirs = [];
foreach ($dirs as $dir) {
	$base = $root . '/' . $dir;
	if (!is_dir($base)) continue;
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
	foreach ($it as $file) {
		/** @var SplFileInfo $file */
		if (!$file->isDir()) continue;
		$path = str_replace( '\\', '/', $file->getPathname() );
		$rel = str_replace($root . '/', '', $path);
		$parts = explode('/', $rel);
		$skip = false;
		foreach ($exclude_dir_names as $ex) {
			if (in_array($ex, $parts, true)) { $skip = true; break; }
		}
		if ($skip) continue;
		// Exception: musicwave/patterns and its subdirs - WordPress registers every PHP file there as pattern, index.php would cause _doing_it_wrong
		if (strpos($rel, 'musicwave/patterns') === 0) {
			continue;
		}
		$all_dirs[] = $path;
	}
}

$missing_index = [];
foreach ($all_dirs as $dir_path) {
	$idx = $dir_path . '/index.php';
	if (!file_exists($idx)) {
		$missing_index[] = str_replace($root . '/', '', $dir_path);
	}
}

if (!empty($missing_index)) {
	$errors[] = "Directories missing index.php (" . count($missing_index) . "):\n - " . implode("\n - ", $missing_index);
}

if (!empty($errors)) {
	fwrite(STDERR, implode("\n\n", $errors) . "\n");
	exit(1);
}

echo "ABSPATH and index.php check passed: " . $checked_php_files . " PHP files checked, " . count($all_dirs) . " dirs checked.\n";
exit(0);
