<?php
/**
 * Verify WordPress-compatible JavaScript translation catalogs.
 *
 * This is intentionally independent of a WordPress installation so it can run
 * in CI before a wp-env container exists.
 */

declare(strict_types=1);

$root  = dirname( __DIR__ );
$cases = array(
	'musicwave' => array(
		'locale'   => 'en_US',
		'handles'  => array(
			'musicwave-theme-preference'    => 'assets/theme-preference.js',
			'musicwave-slider'              => 'assets/slider.js',
			'musicwave-presentation-blocks' => 'assets/editor-blocks.js',
		),
		'expected' => array(
			'استفاده از تنظیمات کلی' => 'Use global setting',
			'اسلایدر'               => 'Slider',
			'شبکه'                  => 'Grid',
			'ویترین'                => 'Shelf',
		),
	),
	'music-wave-core' => array(
		'locale'   => 'en_US',
		'handles'  => array(
			'music-wave-editor'           => 'assets/editor.js',
			'music-wave-dynamic-blocks'   => 'assets/blocks.js',
			'music-wave-metadata-lookup'  => 'assets/metadata-lookup.js',
		),
		'expected' => array(
			'فیلدها'       => 'Fields',
			'نمایش هنرمند' => 'Show artist',
			'چیدمان'       => 'Layout',
		),
	),
);

$total_catalogs = 0;
$total_messages = 0;
foreach ( $cases as $domain => $config ) {
	$messages = array();
	foreach ( $config['handles'] as $handle => $relative_script ) {
		$hash     = md5( $relative_script );
		$filename = sprintf( '%s-%s-%s.json', $domain, $config['locale'], $hash );
		$path     = $root . '/' . ( 'musicwave' === $domain ? 'musicwave' : 'music-wave-core' ) . '/languages/' . $filename;
		if ( ! is_file( $path ) ) {
			fwrite( STDERR, "Missing {$filename} for {$handle}\n" );
			exit( 1 );
		}

		$contents = file_get_contents( $path );
		$data     = false === $contents ? null : json_decode( $contents, true );
		if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
			fwrite( STDERR, "Invalid JSON in {$filename}\n" );
			exit( 1 );
		}
		if ( $relative_script !== (string) ( $data['source'] ?? '' ) ) {
			fwrite( STDERR, "Wrong source path in {$filename}\n" );
			exit( 1 );
		}
		if ( 'messages' !== (string) ( $data['domain'] ?? '' ) || ! isset( $data['locale_data']['messages'] ) ) {
			fwrite( STDERR, "Not a JED 1.x messages catalog: {$filename}\n" );
			exit( 1 );
		}
		$header = $data['locale_data']['messages'][''] ?? array();
		if ( $config['locale'] !== (string) ( $header['lang'] ?? '' ) ) {
			fwrite( STDERR, "Wrong locale header in {$filename}\n" );
			exit( 1 );
		}
		$messages[ $handle ] = $data['locale_data']['messages'];
		++$total_catalogs;
	}

	$reference = reset( $messages );
	foreach ( $config['expected'] as $source => $translation ) {
		if ( ! array_key_exists( $source, $reference ) ) {
			fwrite( STDERR, "Missing expected {$domain} JS translation: {$source}\n" );
			exit( 1 );
		}
		if ( array( $translation ) !== $reference[ $source ] ) {
			fwrite( STDERR, "Unexpected {$domain} translation for {$source}\n" );
			exit( 1 );
		}
	}
	if ( ! isset( $reference['%d قطعه'][0], $reference['%d قطعه'][1] ) && 'musicwave' === $domain ) {
		fwrite( STDERR, "Theme plural catalog entry is missing\n" );
		exit( 1 );
	}
	$total_messages += count( $reference ) - 1;
}

printf( "SCRIPT TRANSLATIONS OK: %d hash-addressed catalogs, %d messages\n", $total_catalogs, $total_messages );
