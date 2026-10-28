<?php
/**
 * Merge each POT into its en_US PO catalog, the way `msgmerge` would.
 *
 * CI cannot assume gettext binaries exist, and hand-editing a 1600-entry PO
 * after every source change is how entries go missing in the first place. This
 * rewrites each PO from its POT so the two can only agree:
 *
 *   - entries in the POT keep whatever English the PO already had,
 *   - entries only in the POT are added with an empty msgstr to translate,
 *   - entries only in the PO are dropped (the source string is gone),
 *   - order and source references follow the POT, so diffs stay reviewable.
 *
 * Persian stays the source language throughout: this only ever moves English
 * into place beside the Persian msgids that generate-pot.php extracted.
 *
 * Usage: php tools/sync-po.php [--dry-run]
 */

declare(strict_types=1);

require __DIR__ . '/lib/po-parser.php';

$root       = dirname( __DIR__ );
$components = array( 'musicwave', 'music-wave-core', 'music-wave-vip' );
$dry_run    = in_array( '--dry-run', array_slice( $argv, 1 ), true );

/**
 * Render one PO entry block.
 *
 * @param array{msgctxt: string, msgid: string, msgid_plural: string, plural: bool, comments: array<int, string>} $entry    POT entry.
 * @param array<int, string>                                                                                     $msgstr   Existing translations by plural index.
 */
function mw_render_entry( array $entry, array $msgstr ): string {
	$out = '';
	foreach ( $entry['comments'] as $comment ) {
		$out .= $comment . "\n";
	}
	if ( '' !== $entry['msgctxt'] ) {
		$out .= 'msgctxt "' . mw_po_quote( $entry['msgctxt'] ) . "\"\n";
	}
	$out .= 'msgid "' . mw_po_quote( $entry['msgid'] ) . "\"\n";

	if ( $entry['plural'] ) {
		$out .= 'msgid_plural "' . mw_po_quote( $entry['msgid_plural'] ) . "\"\n";
		for ( $index = 0; $index < 2; $index++ ) {
			$out .= 'msgstr[' . $index . '] "' . mw_po_quote( $msgstr[ $index ] ?? '' ) . "\"\n";
		}

		return $out . "\n";
	}

	return $out . 'msgstr "' . mw_po_quote( $msgstr[0] ?? '' ) . "\"\n\n";
}

$exit = 0;

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

	// Preserve the PO's own header verbatim: it carries the locale, the plural
	// rule, and the translator credits, none of which the POT knows about.
	$existing = (string) file_get_contents( $po_path );
	$split    = strpos( $existing, "\n\n" );
	$header   = false === $split ? '' : substr( $existing, 0, $split + 2 );
	if ( '' === $header ) {
		fwrite( STDERR, "Could not read the PO header of {$po_path}\n" );
		exit( 1 );
	}

	$content = $header;
	$added   = 0;
	$kept    = 0;

	foreach ( $pot as $key => $entry ) {
		if ( isset( $po[ $key ] ) ) {
			++$kept;
			$content .= mw_render_entry( $entry, $po[ $key ]['msgstr'] );
			continue;
		}
		++$added;
		$content .= mw_render_entry( $entry, array() );
	}

	$dropped = count( array_diff_key( $po, $pot ) );

	printf(
		"%s: %d kept, %d added, %d dropped\n",
		$component,
		$kept,
		$added,
		$dropped
	);

	if ( $dry_run ) {
		continue;
	}

	if ( $content === $existing ) {
		continue;
	}
	if ( false === file_put_contents( $po_path, $content ) ) {
		fwrite( STDERR, "Could not write {$po_path}\n" );
		$exit = 1;
		continue;
	}
	printf( "  wrote %s\n", $po_path );
}

exit( $exit );
