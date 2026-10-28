<?php
/**
 * Validate that bundled MusicWave templates are well-formed.
 */
$dir      = __DIR__ . '/../musicwave/templates';
$files    = glob( $dir . '/*.html' );
$all_ok   = true;
foreach ( $files as $file ) {
	$content = file_get_contents( $file );
	$name    = basename( $file );

	if ( strpos( $content, 'music-sidebar' ) !== false ) {
		echo "FAIL {$name}: still references music-sidebar\n";
		$all_ok = false;
	}

	preg_match_all( '/<!-- wp:group\\b/', $content, $open_comments );
	preg_match_all( '/<!-- \\/wp:group -->/', $content, $close_comments );
	$open_c  = count( $open_comments[0] );
	$close_c = count( $close_comments[0] );
	if ( $open_c !== $close_c ) {
		echo "FAIL {$name}: group comment balance {$open_c} open vs {$close_c} close\n";
		$all_ok = false;
	}

	$open_divs  = substr_count( $content, '<div' );
	$close_divs = substr_count( $content, '</div>' );
	if ( $open_divs !== $close_divs ) {
		echo "FAIL {$name}: div balance {$open_divs} open vs {$close_divs} close\n";
		$all_ok = false;
	}

	preg_match_all( '/<!-- wp:([a-z0-9\\/-]+) ([^>]*?) \\/-->/s', $content, $blocks );
	foreach ( $blocks[2] as $json ) {
		if ( strpos( $json, '{' ) === 0 || strpos( $json, '[' ) === 0 ) {
			json_decode( $json );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				echo "FAIL {$name}: invalid block JSON: " . substr( $json, 0, 80 ) . "\n";
				$all_ok = false;
			}
		}
	}
}
echo $all_ok ? 'ALL TEMPLATES OK' . PHP_EOL : 'ISSUES FOUND' . PHP_EOL;
