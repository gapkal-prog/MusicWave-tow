<?php
/**
 * Compile each en_US PO catalog into the binary MO file WordPress loads.
 *
 * `load_theme_textdomain()` / `load_plugin_textdomain()` read the MO, never the
 * PO, so an edited PO that is not recompiled changes nothing at runtime — the
 * failure is silent and looks exactly like a missing translation. Keeping the
 * compiler in-repo means `composer make-mo` needs no gettext binaries, which CI
 * and most Windows dev boxes do not have.
 *
 * Writes the standard little-endian MO layout (revision 0, no hash table;
 * gettext treats an absent hash table as "look it up linearly", and WordPress
 * builds its own PHP-side index anyway).
 *
 * Usage: php tools/generate-mo.php
 */

declare(strict_types=1);

require __DIR__ . '/lib/po-parser.php';

$root       = dirname( __DIR__ );
$components = array( 'musicwave', 'music-wave-core', 'music-wave-vip' );

/**
 * Build the MO binary for one set of original => translation pairs.
 *
 * @param array<string, string> $pairs Sorted original (with context/plural separators) => translation.
 */
function mw_build_mo( array $pairs ): string {
	// gettext requires the originals table to be sorted by byte value so a
	// consumer may binary-search it.
	ksort( $pairs, SORT_STRING );

	$count       = count( $pairs );
	$originals   = array_keys( $pairs );
	$translations = array_values( $pairs );

	// Header is 7 uint32 fields; the two offset tables follow, 2 uint32 each.
	$header_size    = 28;
	$table_size     = $count * 8;
	$originals_off  = $header_size;
	$translations_off = $originals_off + $table_size;
	$data_off       = $translations_off + $table_size;

	$original_table    = '';
	$translation_table = '';
	$data              = '';

	foreach ( $originals as $index => $original ) {
		$original_table .= pack( 'VV', strlen( $original ), $data_off + strlen( $data ) );
		$data           .= $original . "\0";
	}
	foreach ( $translations as $translation ) {
		$translation_table .= pack( 'VV', strlen( $translation ), $data_off + strlen( $data ) );
		$data              .= $translation . "\0";
	}

	return pack(
		'VVVVVVV',
		0x950412de,       // Little-endian magic.
		0,                // Revision.
		$count,
		$originals_off,
		$translations_off,
		0,                // Hash table size: none.
		$data_off         // Hash table offset (unused).
	) . $original_table . $translation_table . $data;
}

$total = 0;

foreach ( $components as $component ) {
	$po_path = $root . "/{$component}/languages/{$component}-en_US.po";
	$mo_path = $root . "/{$component}/languages/{$component}-en_US.mo";

	if ( ! is_file( $po_path ) ) {
		fwrite( STDERR, "MISSING FILE: {$po_path}\n" );
		exit( 1 );
	}

	// The header entry (empty msgid) carries the metadata gettext expects in
	// the MO as the translation of "". mw_parse_po() skips it by design, so
	// read it straight from the file.
	$existing = (string) file_get_contents( $po_path );
	$split    = strpos( $existing, "\n\n" );
	if ( false === $split ) {
		fwrite( STDERR, "Could not read the PO header of {$po_path}\n" );
		exit( 1 );
	}
	$header_lines = explode( "\n", substr( $existing, 0, $split ) );
	$header       = '';
	foreach ( $header_lines as $line ) {
		$line = trim( $line );
		if ( '' === $line || '"' !== $line[0] ) {
			continue;
		}
		$header .= mw_po_unquote( $line );
	}

	$pairs = array( '' => $header );
	$empty = 0;

	foreach ( mw_parse_po( $po_path ) as $entry ) {
		// EOT separates msgctxt from msgid; NUL separates singular from plural.
		$original = '' === $entry['msgctxt'] ? $entry['msgid'] : $entry['msgctxt'] . "\4" . $entry['msgid'];

		if ( $entry['plural'] ) {
			$original   .= "\0" . $entry['msgid_plural'];
			$forms       = array( $entry['msgstr'][0] ?? '', $entry['msgstr'][1] ?? '' );
			if ( '' === trim( $forms[0] ) || '' === trim( $forms[1] ) ) {
				++$empty;
				continue;
			}
			$pairs[ $original ] = implode( "\0", $forms );
			continue;
		}

		$translation = $entry['msgstr'][0] ?? '';
		// An untranslated entry must stay out of the MO: gettext would
		// otherwise return an empty string instead of falling back to the
		// Persian source.
		if ( '' === trim( $translation ) ) {
			++$empty;
			continue;
		}
		$pairs[ $original ] = $translation;
	}

	$binary = mw_build_mo( $pairs );
	if ( false === file_put_contents( $mo_path, $binary ) ) {
		fwrite( STDERR, "Could not write {$mo_path}\n" );
		exit( 1 );
	}

	printf(
		"Generated %s (%d entries%s)\n",
		$mo_path,
		count( $pairs ),
		$empty > 0 ? ", {$empty} untranslated skipped" : ''
	);
	$total += count( $pairs );
}

printf( "Compiled %d entries.\n", $total );
