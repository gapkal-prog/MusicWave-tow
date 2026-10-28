<?php
/**
 * Generate JED 1.x JSON catalogs for scripts with wp.i18n strings.
 *
 * Usage: php tools/generate-script-json.php
 *
 * WordPress resolves a catalog in a custom language directory by the MD5 of
 * the script path relative to the theme/plugin root. Keep the script list next
 * to the enqueue handles so a renamed asset cannot silently orphan its JSON.
 */

declare(strict_types=1);

$project_root = dirname( __DIR__ );
$locale       = 'en_US';
$revision     = '2026-08-30 00:00+0000';
$catalogs     = array(
	array(
		'component' => 'musicwave',
		'domain'    => 'musicwave',
		'po'        => $project_root . '/musicwave/languages/musicwave-en_US.po',
		'language'  => $project_root . '/musicwave',
		'scripts'   => array(
			'assets/editor-blocks.js',
			'assets/theme-preference.js',
			'assets/slider.js',
		),
	),
	array(
		'component' => 'music-wave-core',
		'domain'    => 'music-wave-core',
		'po'        => $project_root . '/music-wave-core/languages/music-wave-core-en_US.po',
		'language'  => $project_root . '/music-wave-core',
		'scripts'   => array(
			'assets/blocks.js',
			'assets/editor.js',
			'assets/metadata-lookup.js',
		),
	),
);

foreach ( $catalogs as $catalog ) {
	$entries = musicwave_read_po( $catalog['po'] );
	if ( empty( $entries ) ) {
		fwrite( STDERR, "No translated entries found in {$catalog['po']}\n" );
		exit( 1 );
	}

	$language_dir = $catalog['language'] . '/languages';
	if ( ! is_dir( $language_dir ) && ! mkdir( $language_dir, 0775, true ) && ! is_dir( $language_dir ) ) {
		fwrite( STDERR, "Could not create {$language_dir}\n" );
		exit( 1 );
	}

	foreach ( $catalog['scripts'] as $relative_script ) {
		$hash     = md5( $relative_script );
		$filename = sprintf( '%s-%s-%s.json', $catalog['domain'], $locale, $hash );
		$path     = $language_dir . '/' . $filename;
		$payload  = array(
			'translation-revision-date' => $revision,
			'generator'                 => 'MusicWave translation tooling',
			'source'                    => $relative_script,
			'domain'                    => 'messages',
			'locale_data'               => array(
				'messages' => array(
					'' => array(
						'domain'       => 'messages',
						'lang'         => $locale,
						'plural-forms' => 'nplurals=2; plural=(n != 1);',
					),
				),
			),
		);

		foreach ( $entries as $entry ) {
			$key = null !== $entry['context'] && '' !== $entry['context'] ? $entry['context'] . "\004" . $entry['singular'] : $entry['singular'];
			if ( null !== $entry['plural'] && '' !== $entry['plural'] ) {
				$payload['locale_data']['messages'][ $key ] = array(
					$entry['translations'][0] ?? '',
					$entry['translations'][1] ?? '',
				);
			} else {
				$payload['locale_data']['messages'][ $key ] = array( $entry['translations'][0] ?? '' );
			}
		}

		$json = wp_json_pretty( $payload );
		if ( false === file_put_contents( $path, $json . "\n" ) ) {
			fwrite( STDERR, "Could not write {$path}\n" );
			exit( 1 );
		}
		echo "Generated {$path}\n";
	}
}

/**
 * Parse the small, gettext-compatible subset used by WordPress PO catalogs.
 *
 * @return array<int, array<string, mixed>>
 */
function musicwave_read_po( string $path ): array {
	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		return array();
	}

	$entries = array();
	foreach ( preg_split( '/(?:\r\n|\r|\n){2,}/', $contents ) ?: array() as $block ) {
		$fields = array(
			'context'      => null,
			'singular'     => null,
			'plural'       => null,
			'translations' => array(),
		);
		$current = null;

		foreach ( preg_split( '/\r\n|\r|\n/', trim( $block ) ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === ( $line[0] ?? '' ) ) {
				continue;
			}
			if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(".*")$/', $line, $match ) ) {
				$field = $match[1];
				if ( 0 === strpos( $field, 'msgstr[' ) ) {
					$current = 'translations[' . (int) $match[2] . ']';
				} elseif ( 'msgstr' === $field ) {
					$current = 'translations[0]';
				} else {
					$current = array(
						'msgid'        => 'singular',
						'msgid_plural' => 'plural',
						'msgctxt'     => 'context',
					)[ $field ] ?? $field;
				}
				$value = musicwave_po_unquote( $match[3] );
				if ( 'translations[' === substr( $current, 0, 13 ) ) {
					$index = (int) substr( $current, 13, -1 );
					$fields['translations'][ $index ] = $value;
				} else {
					$fields[ $current ] = $value;
				}
				continue;
			}
			if ( null !== $current && preg_match( '/^(".*")$/', $line, $match ) ) {
				$value = musicwave_po_unquote( $match[1] );
				if ( 'translations[' === substr( $current, 0, 13 ) ) {
					$index = (int) substr( $current, 13, -1 );
					$fields['translations'][ $index ] = ( $fields['translations'][ $index ] ?? '' ) . $value;
				} else {
					$fields[ $current ] = ( $fields[ $current ] ?? '' ) . $value;
				}
			}
		}

		if ( null === $fields['singular'] || '' === $fields['singular'] || empty( $fields['translations'] ) ) {
			continue;
		}
		ksort( $fields['translations'] );
		$entries[] = $fields;
	}

	return $entries;
}

/**
 * Decode a quoted PO value without requiring a gettext extension.
 */
function musicwave_po_unquote( string $value ): string {
	$decoded = json_decode( $value, true );
	if ( is_string( $decoded ) ) {
		return $decoded;
	}
	return stripcslashes( substr( $value, 1, -1 ) );
}

/**
 * Emit stable, human-reviewable JSON.
 */
function wp_json_pretty( array $payload ): string {
	$json = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	return false === $json ? '' : $json;
}
