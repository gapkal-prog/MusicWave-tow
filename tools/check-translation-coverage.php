<?php
/**
 * Verify the en_US translation layer fully covers the Persian source strings.
 *
 * Persian is this project's source language: every `__()` argument in PHP and
 * JavaScript is Persian, the POT holds those Persian msgids, and `en_US` is a
 * real translation layer rather than a pass-through. That inverts the usual
 * WordPress setup, and it removes the safety net gettext normally provides: a
 * Persian string added without a matching en_US entry ships Persian text to
 * English visitors, and no tool complains, because an untranslated msgid
 * legitimately falls back to itself.
 *
 * This gate closes that gap by asserting, per component:
 *   1. every POT msgid exists in the PO file (no missing English),
 *   2. every PO msgid still exists in the POT (no stale entry left behind
 *      after a source string is reworded),
 *   3. no PO entry is empty or fuzzy, counting every plural form.
 *
 * Usage: php tools/check-translation-coverage.php
 */

declare(strict_types=1);

require __DIR__ . '/lib/po-parser.php';

$root       = dirname( __DIR__ );
$components = array( 'musicwave', 'music-wave-core', 'music-wave-vip' );

/**
 * Shorten a msgid for a single-line report row.
 */
function mw_excerpt( string $value ): string {
	$value = str_replace( array( "\n", "\r", "\t" ), ' ', $value );
	if ( mb_strlen( $value ) <= 60 ) {
		return $value;
	}

	return mb_substr( $value, 0, 57 ) . '...';
}

$failures = 0;
$checks   = 0;

foreach ( $components as $component ) {
	$pot_path = $root . "/{$component}/languages/{$component}.pot";
	$po_path  = $root . "/{$component}/languages/{$component}-en_US.po";

	foreach ( array( $pot_path, $po_path ) as $path ) {
		if ( ! is_file( $path ) ) {
			fwrite( STDERR, "MISSING FILE: {$path}\n" );
			exit( 1 );
		}
	}

	$pot = mw_parse_po( $pot_path );
	$po  = mw_parse_po( $po_path );

	$missing      = array();
	$untranslated = array();
	$fuzzy        = array();
	$stale        = array();

	foreach ( $pot as $key => $entry ) {
		++$checks;
		if ( ! isset( $po[ $key ] ) ) {
			$missing[] = $entry['msgid'];
			continue;
		}

		$target = $po[ $key ];
		if ( $target['fuzzy'] ) {
			$fuzzy[] = $entry['msgid'];
		}

		// Plural entries need English for both forms; singulars need msgstr[0].
		$required = $entry['plural'] ? 2 : 1;
		for ( $index = 0; $index < $required; $index++ ) {
			if ( '' === trim( $target['msgstr'][ $index ] ?? '' ) ) {
				$untranslated[] = $entry['msgid'];
				break;
			}
		}
	}

	foreach ( $po as $key => $entry ) {
		if ( ! isset( $pot[ $key ] ) ) {
			$stale[] = $entry['msgid'];
		}
	}

	printf(
		"%s: %d source strings, %d translated entries\n",
		$component,
		count( $pot ),
		count( $po )
	);

	$report = array(
		'missing English for'        => $missing,
		'empty translation for'      => $untranslated,
		'fuzzy translation for'      => $fuzzy,
		'stale PO entry (no source)' => $stale,
	);
	foreach ( $report as $label => $items ) {
		if ( empty( $items ) ) {
			continue;
		}
		$failures += count( $items );
		printf( "  %d %s:\n", count( $items ), $label );
		foreach ( array_slice( $items, 0, 25 ) as $item ) {
			printf( "    - %s\n", mw_excerpt( $item ) );
		}
		if ( count( $items ) > 25 ) {
			printf( "    ... and %d more\n", count( $items ) - 25 );
		}
	}
}

if ( $failures > 0 ) {
	printf( "\nTRANSLATION COVERAGE FAILED (%d problems)\n", $failures );
	exit( 1 );
}

printf( "\nTRANSLATION COVERAGE OK (%d source strings verified)\n", $checks );
