<?php
/**
 * Minimal PO/POT reader shared by the translation tooling.
 *
 * Deliberately dependency-free: the translation gates run in CI before any
 * WordPress install or gettext binary exists, so they cannot lean on
 * `msgfmt`/`msgcmp` or on WordPress's own PO classes.
 */

declare(strict_types=1);

/**
 * Decode the escape sequences of one quoted PO string literal.
 */
function mw_po_unquote( string $literal ): string {
	$literal = trim( $literal );
	if ( strlen( $literal ) < 2 || '"' !== $literal[0] ) {
		return '';
	}

	return str_replace(
		array( '\\\\', '\\"', '\\n', '\\t', '\\r' ),
		array( '\\', '"', "\n", "\t", "\r" ),
		substr( $literal, 1, -1 )
	);
}

/**
 * Escape one string back into a PO string literal body.
 */
function mw_po_quote( string $value ): string {
	return str_replace(
		array( '\\', '"', "\n", "\t", "\r" ),
		array( '\\\\', '\\"', '\\n', '\\t', '\\r' ),
		$value
	);
}

/**
 * Build the context-qualified key gettext uses to identify an entry.
 *
 * A msgid alone is not unique: the same Persian word can carry different
 * msgctxt values and must then resolve to different English words.
 */
function mw_po_key( string $msgctxt, string $msgid ): string {
	return '' === $msgctxt ? $msgid : $msgctxt . "\4" . $msgid;
}

/**
 * Parse a PO/POT file into entries keyed by context-qualified msgid.
 *
 * The header entry (empty msgid, no context) is metadata and is skipped.
 *
 * @param string $path Absolute path to the .po or .pot file.
 * @return array<string, array{msgctxt: string, msgid: string, msgid_plural: string, plural: bool, msgstr: array<int, string>, fuzzy: bool, comments: array<int, string>}>
 */
function mw_parse_po( string $path ): array {
	$lines = file( $path, FILE_IGNORE_NEW_LINES );
	if ( false === $lines ) {
		fwrite( STDERR, "Could not read {$path}\n" );
		exit( 1 );
	}

	$entries = array();
	$current = null;
	$field   = '';

	$start = static function () use ( &$current ): void {
		if ( null === $current ) {
			$current = array(
				'msgctxt'      => '',
				'msgid'        => '',
				'msgid_plural' => '',
				'msgstr'       => array(),
				'fuzzy'        => false,
				'comments'     => array(),
			);
		}
	};

	$flush = static function () use ( &$entries, &$current ): void {
		if ( null === $current ) {
			return;
		}
		if ( '' !== $current['msgid'] || '' !== $current['msgctxt'] ) {
			$current['plural'] = '' !== $current['msgid_plural'];
			$entries[ mw_po_key( $current['msgctxt'], $current['msgid'] ) ] = $current;
		}
		$current = null;
	};

	foreach ( $lines as $line ) {
		$line = trim( $line );

		if ( '' === $line ) {
			$flush();
			$field = '';
			continue;
		}

		if ( '#' === $line[0] ) {
			$start();
			if ( 0 === strpos( $line, '#,' ) && false !== strpos( $line, 'fuzzy' ) ) {
				$current['fuzzy'] = true;
			}
			$current['comments'][] = $line;
			continue;
		}

		// A bare quoted string continues whichever field is open.
		if ( '"' === $line[0] ) {
			if ( null === $current || '' === $field ) {
				continue;
			}
			$value = mw_po_unquote( $line );
			if ( in_array( $field, array( 'msgctxt', 'msgid', 'msgid_plural' ), true ) ) {
				$current[ $field ] .= $value;
			} else {
				$index                       = (int) $field;
				$current['msgstr'][ $index ] = ( $current['msgstr'][ $index ] ?? '' ) . $value;
			}
			continue;
		}

		if ( preg_match( '/^(msgctxt|msgid_plural|msgid)\s+(".*")$/', $line, $matches ) ) {
			// msgctxt always opens a new entry. So does msgid, unless a
			// msgctxt or comment block already opened this one.
			if ( 'msgctxt' === $matches[1] || ( 'msgid' === $matches[1] && null !== $current && '' !== $current['msgid'] ) ) {
				$flush();
			}
			$start();
			$field             = $matches[1];
			$current[ $field ] = mw_po_unquote( $matches[2] );
			continue;
		}

		if ( preg_match( '/^msgstr(?:\[(\d+)\])?\s+(".*")$/', $line, $matches ) ) {
			$start();
			$index                       = '' === ( $matches[1] ?? '' ) ? 0 : (int) $matches[1];
			$field                       = (string) $index;
			$current['msgstr'][ $index ] = mw_po_unquote( $matches[2] );
			continue;
		}
	}

	$flush();

	return $entries;
}
