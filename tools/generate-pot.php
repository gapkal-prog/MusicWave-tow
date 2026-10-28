<?php
/**
 * Generate translation template files.
 *
 * Usage: php tools/generate-pot.php
 */

declare(strict_types=1);

$project_root = dirname( __DIR__ );
$components   = array(
	'musicwave'       => 'musicwave',
	'music-wave-core' => 'music-wave-core',
	'music-wave-vip'  => 'music-wave-vip',
);
foreach ( $components as $component => $domain ) {
	$source      = $project_root . DIRECTORY_SEPARATOR . $component;
	$languages   = $source . DIRECTORY_SEPARATOR . 'languages';
	$destination = $languages . DIRECTORY_SEPARATOR . $domain . '.pot';

	if ( ! is_dir( $languages ) && ! mkdir( $languages, 0775, true ) && ! is_dir( $languages ) ) {
		fwrite( STDERR, "Could not create language directory: {$languages}\n" );
		exit( 1 );
	}

	$entries = musicwave_pot_entries( $source, $domain );
	$content = musicwave_pot_header( $component, $domain );
	foreach ( $entries as $entry ) {
		$content .= musicwave_pot_entry( $entry );
	}
	if ( false === file_put_contents( $destination, $content ) ) {
		fwrite( STDERR, "Could not write POT file: {$destination}\n" );
		exit( 1 );
	}

	echo "Generated {$destination}\n";
}

/**
 * Extract supported WordPress translation calls from PHP and JavaScript.
 *
 * The generator intentionally supports the translation functions used by this
 * repository and avoids a build-time dependency for release packaging.
 *
 * @return array<string, array<string, mixed>>
 */
function musicwave_pot_entries( string $source, string $domain ): array {
	$entries = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	/** @var SplFileInfo $file */
	foreach ( $iterator as $file ) {
		$extension = strtolower( $file->getExtension() );
		if ( ! $file->isFile() || ! in_array( $extension, array( 'php', 'js' ), true ) ) {
			continue;
		}

		$path = $file->getPathname();
		$php  = file_get_contents( $path );
		if ( false === $php ) {
			continue;
		}

		$relative = str_replace( DIRECTORY_SEPARATOR, '/', ltrim( substr( $path, strlen( rtrim( $source, DIRECTORY_SEPARATOR ) ) ), DIRECTORY_SEPARATOR ) );
		foreach ( musicwave_pot_matches( $php, $domain ) as $match ) {
			$line = 1 + substr_count( substr( $php, 0, $match['offset'] ), "\n" );
			$key  = $match['context'] . "\004" . $match['singular'] . "\004" . $match['plural'];
			if ( ! isset( $entries[ $key ] ) ) {
				$entries[ $key ] = array(
					'context'  => $match['context'],
					'singular' => $match['singular'],
					'plural'   => $match['plural'],
					'refs'     => array(),
				);
			}
			$entries[ $key ]['refs'][] = $relative . ':' . $line;
		}
	}

	// Normalize singular calls that share an msgid with an _n() pair. PO
	// catalogs cannot contain both forms without a gettext context; keeping
	// the plural entry preserves the complete runtime contract.
	foreach ( $entries as $plural_key => $plural_entry ) {
		if ( '' === $plural_entry['plural'] ) {
			continue;
		}
		foreach ( $entries as $singular_key => $singular_entry ) {
			if ( $plural_key === $singular_key || '' !== $singular_entry['plural'] ) {
				continue;
			}
			if ( $plural_entry['context'] === $singular_entry['context'] && $plural_entry['singular'] === $singular_entry['singular'] ) {
				$entries[ $plural_key ]['refs'] = array_merge( $entries[ $plural_key ]['refs'], $singular_entry['refs'] );
				unset( $entries[ $singular_key ] );
			}
		}
	}

	foreach ( musicwave_pot_metadata_entries( $source, $domain ) as $metadata ) {
		$key = $metadata['context'] . "\004" . $metadata['singular'] . "\004" . $metadata['plural'];
		if ( isset( $entries[ $key ] ) ) {
			$entries[ $key ]['refs'] = array_merge( $entries[ $key ]['refs'], $metadata['refs'] );
			continue;
		}

		// A metadata keyword can be the singular side of an _n() pair. A
		// gettext catalog cannot define both a singular-only entry and the
		// same msgid as a plural entry, so attach the metadata reference to
		// the existing plural entry instead of emitting a duplicate msgid.
		$merged = false;
		foreach ( $entries as $existing_key => $existing ) {
			if ( $existing['context'] === $metadata['context'] && $existing['singular'] === $metadata['singular'] ) {
				$entries[ $existing_key ]['refs'] = array_merge( $entries[ $existing_key ]['refs'], $metadata['refs'] );
				$merged = true;
				break;
			}
		}
		if ( ! $merged ) {
			$entries[ $key ] = $metadata;
		}
	}

	ksort( $entries, SORT_STRING );
	foreach ( $entries as &$entry ) {
		$entry['refs'] = array_values( array_unique( $entry['refs'] ) );
	}
	unset( $entry );

	return $entries;
}

/**
 * Extract display metadata from block.json, theme.json, and pattern headers.
 *
 * These values are user-facing in the inserter and Site Editor but are not
 * wrapped in PHP or JavaScript translation calls. Keeping them in the same
 * catalog prevents source-language drift between the editor and runtime.
 *
 * @return array<int, array<string, mixed>>
 */
function musicwave_pot_metadata_entries( string $source, string $domain ): array {
	$entries = array();

	$add = static function ( string $value, string $reference ) use ( &$entries ): void {
		if ( '' === trim( $value ) ) {
			return;
		}
		$key = "\004" . $value . "\004";
		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = array(
				'context'  => '',
				'singular' => $value,
				'plural'   => '',
				'refs'     => array(),
			);
		}
		$entries[ $key ]['refs'][] = $reference;
	};

	foreach ( glob( $source . '/blocks/*/block.json' ) ?: array() as $path ) {
		$contents = file_get_contents( $path );
		$metadata = false === $contents ? null : json_decode( $contents, true );
		if ( ! is_array( $metadata ) || (string) ( $metadata['textdomain'] ?? '' ) !== $domain ) {
			continue;
		}
		$relative = str_replace( DIRECTORY_SEPARATOR, '/', ltrim( substr( $path, strlen( rtrim( $source, DIRECTORY_SEPARATOR ) ) ), DIRECTORY_SEPARATOR ) );
		foreach ( array( 'title', 'description' ) as $field ) {
			if ( isset( $metadata[ $field ] ) && is_string( $metadata[ $field ] ) ) {
				$add( $metadata[ $field ], $relative );
			}
		}
		if ( isset( $metadata['keywords'] ) && is_array( $metadata['keywords'] ) ) {
			foreach ( $metadata['keywords'] as $keyword ) {
				if ( is_string( $keyword ) ) {
					$add( $keyword, $relative );
				}
			}
		}
	}

	$theme_json_path = $source . '/theme.json';
	if ( is_file( $theme_json_path ) ) {
		$contents = file_get_contents( $theme_json_path );
		$metadata = false === $contents ? null : json_decode( $contents, true );
		if ( is_array( $metadata ) ) {
			foreach ( array( 'customTemplates', 'templateParts' ) as $collection ) {
				if ( ! isset( $metadata[ $collection ] ) || ! is_array( $metadata[ $collection ] ) ) {
					continue;
				}
				foreach ( $metadata[ $collection ] as $template ) {
					if ( is_array( $template ) && isset( $template['title'] ) && is_string( $template['title'] ) ) {
						$add( $template['title'], 'theme.json' );
					}
				}
			}

			// WordPress translates these preset names through the theme.json
			// i18n schema, so keep their Persian source strings in the POT too.
			$preset_collections = array(
				array( 'settings', 'color', 'gradients' ),
				array( 'settings', 'color', 'palette' ),
				array( 'settings', 'color', 'duotone' ),
				array( 'settings', 'spacing', 'spacingSizes' ),
				array( 'settings', 'typography', 'fontFamilies' ),
				array( 'settings', 'typography', 'fontSizes' ),
				array( 'settings', 'shadow', 'presets' ),
			);
			foreach ( $preset_collections as $path_parts ) {
				$cursor = $metadata;
				foreach ( $path_parts as $part ) {
					$cursor = is_array( $cursor ) && isset( $cursor[ $part ] ) ? $cursor[ $part ] : null;
				}
				if ( ! is_array( $cursor ) ) {
					continue;
				}
				foreach ( $cursor as $preset ) {
					if ( is_array( $preset ) && isset( $preset['name'] ) && is_string( $preset['name'] ) ) {
						$add( $preset['name'], 'theme.json' );
					}
				}
			}
		}
	}

	if ( is_dir( $source . '/patterns' ) ) {
		foreach ( glob( $source . '/patterns/*.php' ) ?: array() as $path ) {
			$contents = file_get_contents( $path );
			if ( false === $contents ) {
				continue;
			}
			if ( preg_match( '/^\s*\*\s+Title:\s+(.+)$/m', $contents, $match ) ) {
				$relative = str_replace( DIRECTORY_SEPARATOR, '/', ltrim( substr( $path, strlen( rtrim( $source, DIRECTORY_SEPARATOR ) ) ), DIRECTORY_SEPARATOR ) );
				$add( trim( $match[1] ), $relative );
			}
		}
	}

	return array_values( $entries );
}

/**
 * Find known translation function calls for one text domain.
 *
 * @return array<int, array<string, string|int>>
 */
function musicwave_pot_matches( string $php, string $domain ): array {
	$quoted = "(['\"])((?:\\\\.|(?!\\1).)*)\\1";
	$domain_pattern = preg_quote( $domain, '/' );
	$patterns = array(
		'/\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\s*\(\s*' . $quoted . '\s*,\s*([\'"])' . $domain_pattern . '\3\s*\)/s',
		'/\b(?:_x|esc_html_x|esc_attr_x)\s*\(\s*' . $quoted . '\s*,\s*' . $quoted . '\s*,\s*([\'"])' . $domain_pattern . '\5\s*\)/s',
		'/\b_n\s*\(\s*' . $quoted . '\s*,\s*' . $quoted . '\s*,.*?,\s*([\'"])' . $domain_pattern . '\5\s*\)/s',
	);
	$matches = array();

	foreach ( $patterns as $index => $pattern ) {
		$found = array();
		$count = preg_match_all( $pattern, $php, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		if ( false === $count || 0 === $count ) {
			continue;
		}
		foreach ( $found as $item ) {
			$matches[] = array(
				'context'  => 1 === $index ? stripcslashes( $item[4][0] ) : '',
				'singular' => stripcslashes( $item[2][0] ),
				'plural'   => 2 === $index ? stripcslashes( $item[4][0] ) : '',
				'offset'   => $item[0][1],
			);
		}
	}

	return $matches;
}

/**
 * Return a deterministic POT header.
 *
 * @return string
 */
function musicwave_pot_header( string $component, string $domain ): string {
	$headers = array(
		'Project-Id-Version: ' . $component,
		'Report-Msgid-Bugs-To: ',
		'POT-Creation-Date: ',
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=UTF-8',
		'Content-Transfer-Encoding: 8bit',
		'X-Domain: ' . $domain,
	);
	$output = "msgid \"\"\nmsgstr \"\"\n";
	foreach ( $headers as $header ) {
		$output .= '"' . musicwave_pot_escape( $header ) . "\\n\"\n";
	}

	return $output . "\n";
}

/**
 * Render one POT entry.
 *
 * @param array<string, mixed> $entry Translation entry.
 * @return string
 */
function musicwave_pot_entry( array $entry ): string {
	$output = '#: ' . implode( ' ', $entry['refs'] ) . "\n";
	if ( false !== strpos( (string) $entry['singular'] . (string) $entry['plural'], '%' ) ) {
		$output .= "#, php-format\n";
	}
	if ( '' !== (string) $entry['context'] ) {
		$output .= 'msgctxt "' . musicwave_pot_escape( (string) $entry['context'] ) . '"' . "\n";
	}
	$output .= 'msgid "' . musicwave_pot_escape( (string) $entry['singular'] ) . '"' . "\n";
	if ( '' !== (string) $entry['plural'] ) {
		return $output . 'msgid_plural "' . musicwave_pot_escape( (string) $entry['plural'] ) . '"' . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n\n";
	}

	return $output . "msgstr \"\"\n\n";
}

/**
 * Escape a PO string.
 *
 * @return string
 */
function musicwave_pot_escape( string $value ): string {
	return str_replace( array( '\\', '"', "\r", "\n" ), array( '\\\\', '\\"', '', '\\n' ), $value );
}
