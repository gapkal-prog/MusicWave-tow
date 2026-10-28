<?php
/**
 * Check strict_types and namespace order — prevents Fatal errors like:
 * - strict_types declaration must be the very first statement
 * - Namespace declaration has to be very first statement or after declare
 *
 * Usage: php tools/check-strict-types.php
 */

if (!defined('ABSPATH')) {
    if (php_sapi_name() !== 'cli' && !defined('WP_CLI')) {
        exit;
    }
}

$root = dirname(__DIR__);
$dirs = ['musicwave', 'music-wave-core', 'music-wave-vip'];
$errors = [];

foreach ($dirs as $dir) {
    $base = $root . '/' . $dir;
    if (!is_dir($base)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() !== 'php') continue;
        $path = $file->getPathname();
        if (strpos($path, '/vendor/') !== false || strpos($path, '/node_modules/') !== false) continue;
        $content = file_get_contents($path);
        if ($content === false) continue;
        if (false === strpos($content, 'declare(strict_types=1);')) continue;

        $phpPos = strpos($content, '<?php');
        if (false === $phpPos) continue;
        $declPos = strpos($content, 'declare(strict_types=1);', $phpPos);
        if (false === $declPos) continue;

        // Check if any code before declare (excluding whitespace and comments)
        $beforeDecl = substr($content, $phpPos + 5, $declPos - ($phpPos + 5));
        // Remove comments
        $beforeDeclClean = preg_replace('#/\*.*?\*/#s', '', $beforeDecl);
        $beforeDeclClean = preg_replace('#//.*#', '', $beforeDeclClean);
        $beforeDeclClean = trim($beforeDeclClean);
        if ('' !== $beforeDeclClean) {
            $errors[] = $path . " - code before declare: " . substr($beforeDeclClean, 0, 100);
            continue;
        }

        // Find real namespace declaration
        if (preg_match('/^\s*namespace\s+[^;]+;/m', $content, $m, PREG_OFFSET_CAPTURE)) {
            $nsPos = $m[0][1];
            if ($nsPos < $declPos) {
                $errors[] = $path . " - namespace before declare";
                continue;
            }
            // Check if ABSPATH guard is between declare and namespace
            $abPos = strpos($content, "defined( 'ABSPATH' )", $declPos);
            if (false === $abPos) {
                $abPos = strpos($content, 'defined("ABSPATH")', $declPos);
            }
            if (false !== $abPos && $abPos < $nsPos) {
                $errors[] = $path . " - ABSPATH guard between declare and namespace (must be after namespace)";
            }
            // Check code between declare and namespace besides comments
            $between = substr($content, $declPos + strlen('declare(strict_types=1);'), $nsPos - ($declPos + strlen('declare(strict_types=1);')));
            $betweenClean = preg_replace('#/\*.*?\*/#s', '', $between);
            $betweenClean = preg_replace('#//.*#', '', $betweenClean);
            $betweenClean = trim($betweenClean);
            if ('' !== $betweenClean) {
                // Allow empty, but if contains if( or other code, it's error
                if (preg_match('/\bif\s*\(/', $betweenClean)) {
                    $errors[] = $path . " - code between declare and namespace: " . substr($betweenClean, 0, 100);
                }
            }
        }
    }
}

if (!empty($errors)) {
    fwrite(STDERR, "Strict types / namespace order errors (" . count($errors) . "):\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}

echo "Strict types and namespace order check passed.\n";
exit(0);
