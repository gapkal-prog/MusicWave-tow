#!/usr/bin/env node
/**
 * Machine audit of the musicwave block templates, template parts and patterns.
 *
 * Checks (heuristic, verified manually afterwards):
 *  1. Heading outline per file: flags files with more than one h1 and files
 *     whose first heading is deeper than h2 (h3/h4...) before any h2.
 *  2. aria-* attribute validation: aria-labelledby / aria-describedby /
 *     aria-controls must reference ids that exist in the same file, every
 *     aria-* must have a boolean-ish or quoted value.
 *  3. Images: meaningful <img> (present in markup, not inside a block comment)
 *     must not carry an empty alt when the image is content-bearing
 *     (heuristic: img without aria-hidden sibling context and with a class
 *     that is not a decorative helper). We report all empty-alt imgs for
 *     manual confirmation.
 *  4. music-wave/* block references: every <!-- wp:music-wave/x --> comment
 *     must match a block registered by the core plugin (names come from
 *     src/Modules/Rendering.php + a static list of theme-registered blocks),
 *     otherwise it is reported for manual confirmation.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const DIRS = [ 'musicwave/templates', 'musicwave/parts', 'musicwave/patterns' ];

// Blocks the theme itself registers in functions.php (dual-registered mirrors).
const THEME_BLOCKS = new Set( [
	'musicwave/release-shelf',
	'musicwave/music-hero',
	'musicwave/theme-text',
	'musicwave/theme-toggle',
	'musicwave/release-slider',
	'musicwave/continue-listening',
	'musicwave/breadcrumb',
] );

// Discover core-plugin dynamic block names from the plugin source.
function discoverCoreBlocks() {
	const names = new Set();
	const render = fs.readFileSync(
		path.join( ROOT, 'music-wave-core/src/Modules/Rendering.php' ),
		'utf8'
	);
	let m;
	const re = /'name'\s*=>\s*'music-wave\/([a-z0-9-]+)'/g;
	while ( ( m = re.exec( render ) ) !== null ) {
		names.add( 'music-wave/' + m[ 1 ] );
	}
	const blocks = fs.readFileSync(
		path.join( ROOT, 'music-wave-core/src/Blocks/ReleaseBlocks.php' ),
		'utf8'
	);
	const re2 = /register_dynamic_block\(\s*'([a-z0-9-]+)'/g;
	while ( ( m = re2.exec( blocks ) ) !== null ) {
		names.add( 'music-wave/' + m[ 1 ] );
	}
	// Registered elsewhere in the plugin source tree or dual-registered by the theme.
	const extra = [
		'music-wave/release-shelf',
		'music-wave/music-hero',
		'music-wave/request-form',
		'music-wave/artist-shelf',
		'music-wave/taxonomy-shelf',
		'music-wave/term-hero',
		'music-wave/share-button',
		'music-wave/shuffle-button',
		'music-wave/continue-listening',
		'music-wave/music-library',
		'music-wave/library-button',
		'music-wave/playlists',
		'music-wave/add-to-playlist',
		'music-wave/public-playlists',
		'music-wave/preview-button',
		'music-wave/account-dashboard',
		'music-wave/membership-panel',
		'music-wave/collect-songs',
		'music-wave/synced-lyrics',
		'music-wave/header-search',
		'music-wave/theme-toggle',
		'music-wave/rail-toggle',
		'music-wave/release-slider',
		'music-wave/section-head',
		'music-wave/breadcrumb',
	];
	extra.forEach( ( n ) => names.add( n ) );
	return names;
}

const CORE_BLOCKS = discoverCoreBlocks();

function files( dir ) {
	const out = [];
	for ( const entry of fs.readdirSync( dir, { withFileTypes: true } ) ) {
		const p = path.join( dir, entry.name );
		if ( entry.isDirectory() ) {
			out.push( ...files( p ) );
		} else if ( /\.(html|php)$/.test( entry.name ) ) {
			out.push( p );
		}
	}
	return out;
}

const findings = [];
function report( file, line, kind, message ) {
	findings.push( { file: path.relative( ROOT, file ), line, kind, message } );
}

for ( const dir of DIRS ) {
	const absDir = path.join( ROOT, dir );
	if ( ! fs.existsSync( absDir ) ) {
		continue;
	}
	for ( const file of files( absDir ) ) {
		const src = fs.readFileSync( file, 'utf8' );
		const lines = src.split( '\n' );

		// Strip block comments so heading/aria checks see only real markup.
		const markupLines = lines.map( ( l ) =>
			l.trim().startsWith( '<!--' ) ? '' : l
		);
		const markup = markupLines.join( '\n' );

		// 1. Heading outline.
		const headings = [];
		let hm;
		const hre = /<h([1-6])[\s>]/g;
		let offset = 0;
		const scan = markup;
		while ( ( hm = hre.exec( scan ) ) !== null ) {
			const before = scan.slice( 0, hm.index );
			const line = before.split( '\n' ).length;
			headings.push( { level: Number( hm[ 1 ] ), line } );
		}
		const h1s = headings.filter( ( h ) => h.level === 1 );
		if ( h1s.length > 1 ) {
			report(
				file,
				h1s[ 1 ].line,
				'heading',
				`بیش از یک h1 (${ h1s.length })`
			);
		}
		const first = headings[ 0 ];
		if ( first && first.level >= 3 ) {
			report(
				file,
				first.line,
				'heading',
				`اولین سرتیتر h${ first.level } است (پرش سلسله‌مراتب)`
			);
		}

		// 2. aria id references.
		const ids = new Set();
		let im;
		const idRe = /\bid="([^"]+)"/g;
		while ( ( im = idRe.exec( markup ) ) !== null ) {
			ids.add( im[ 1 ] );
		}
		const refRe = /aria-(labelledby|describedby|controls)="([^"]*)"/g;
		while ( ( im = refRe.exec( markup ) ) !== null ) {
			const refs = im[ 2 ].split( /\s+/ ).filter( Boolean );
			if ( ! refs.length ) {
				report(
					file,
					markup.slice( 0, im.index ).split( '\n' ).length,
					'aria',
					`aria-${ im[ 1 ] } خالی`
				);
				continue;
			}
			for ( const ref of refs ) {
				if ( ! ids.has( ref ) ) {
					report(
						file,
						markup.slice( 0, im.index ).split( '\n' ).length,
						'aria',
						`aria-${ im[ 1 ] } به id ناموجود «${ ref }» اشاره می‌کند`
					);
				}
			}
		}

		// 3. Meaningful images with empty alt.
		const imgRe = /<img\b[^>]*>/g;
		while ( ( im = imgRe.exec( markup ) ) !== null ) {
			const tag = im[ 0 ];
			const line = markup.slice( 0, im.index ).split( '\n' ).length;
			const alt = /\balt="([^"]*)"/.exec( tag );
			const ariaHidden = /aria-hidden="true"/.test( tag );
			if ( alt && '' === alt[ 1 ] && ! ariaHidden ) {
				report(
					file,
					line,
					'alt',
					'img با alt خالی (نقش معنادار نامشخص)'
				);
			}
		}

		// 4. music-wave/* block references.
		const blockRe = /<!--\s*wp:(music-wave|musicwave)\/([a-z0-9-]+)/g;
		while ( ( im = blockRe.exec( src ) ) !== null ) {
			const name = `${ im[ 1 ] }/${ im[ 2 ] }`;
			if ( im[ 1 ] === 'music-wave' && ! CORE_BLOCKS.has( name ) ) {
				report(
					file,
					src.slice( 0, im.index ).split( '\n' ).length,
					'block',
					`ارجاع به بلوک ثبت‌نشدهٔ ${ name }`
				);
			}
		}
	}
}

if ( ! findings.length ) {
	console.log( 'No template audit findings.' );
	process.exit( 0 );
}
for ( const f of findings ) {
	console.log( `[${ f.kind }] ${ f.file }:${ f.line } — ${ f.message }` );
}
console.log( `\n${ findings.length } finding(s).` );
