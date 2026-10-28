<?php
/**
 * Detect literal NUL bytes across every distributable package.
 *
 * A NUL byte inside block-comment JSON makes json_decode() fail, so WordPress
 * renders the block with default attributes: layout, alignment, titles and
 * template-part slugs all silently vanish from the page. This check covers
 * every text file in all three packages, not just templates, so corruption
 * cannot hide in a pattern, stylesheet or script.
 *
 * Usage: php tools/check-nul-bytes.php
 */

declare(strict_types=1);

$root      = dirname( __DIR__ );
$dirs      = array( 'musicwave', 'music-wave-core', 'music-wave-vip' );
$extension = array( 'html', 'php', 'css', 'js', 'json', 'po', 'pot', 'md', 'txt' );
$bad       = 0;
$checked   = 0;

foreach ( $dirs as $dir ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir ) );
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || ! in_array( strtolower( $file->getExtension() ), $extension, true ) ) {
			continue;
		}
		++$checked;
		$content = (string) file_get_contents( $file->getPathname() );
		$offsets = array();
		$pos     = strpos( $content, "\0" );
		while ( false !== $pos ) {
			$offsets[] = $pos;
			$pos       = strpos( $content, "\0", $pos + 1 );
		}
		if ( array() === $offsets ) {
			continue;
		}
		++$bad;
		$relative = str_replace( $root . DIRECTORY_SEPARATOR, '', $file->getPathname() );
		$line     = substr_count( substr( $content, 0, (int) $offsets[0] ), "\n" ) + 1;
		echo "FAIL {$relative}: " . count( $offsets ) . " NUL byte(s), first on line {$line}\n";
	}
}

echo "Checked {$checked} files.\n";
echo 0 === $bad ? "NO NUL BYTES\n" : "NUL BYTES FOUND\n";
exit( 0 === $bad ? 0 : 1 );
