/**
 * Runtime contract for the theme's two editor registration lanes.
 *
 * Static grep assertions (tests/template-integrity.php) can prove a string is
 * present; they cannot prove that `edit()` really hands InnerBlocks an unlocked
 * template, or that `save()` really emits InnerBlocks.Content — and for a static
 * block those two functions *are* the frontend markup. This suite executes the
 * real musicwave/assets/editor-blocks.js against a stubbed wp.* and asserts what
 * each lane actually does.
 *
 * Lane rule (docs/composability-architecture.md §5): a block either renders
 * itself in PHP (leaf + ServerSideRender preview) or stores itself from JS
 * (static container + InnerBlocks), never both. Both halves are asserted here,
 * so the five shipping PHP-rendered blocks are covered against regressions too.
 *
 * Dependency-free on purpose: plain node, so it runs in the existing JavaScript
 * CI job without a WordPress instance.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

const themeDir = path.join( __dirname, '..', 'musicwave' );
const scriptFile = path.join( themeDir, 'assets', 'editor-blocks.js' );

let failures = 0;

function check( condition, message ) {
	if ( ! condition ) {
		failures += 1;
		console.error( 'FAIL: ' + message );
	}
}

function same( expected, actual, message ) {
	check(
		JSON.stringify( expected ) === JSON.stringify( actual ),
		message +
			' (expected ' +
			JSON.stringify( expected ) +
			', got ' +
			JSON.stringify( actual ) +
			')'
	);
}

/**
 * Mirrors musicwave_block_metadata_entry(): block.json is the single source of
 * truth and the browser payload is a field pass-through, so this is a test
 * double of that contract rather than a second source of truth. If PHP changes
 * the entry shape, the helper contract in tests/template-integrity.php fails.
 *
 * @param {string} dir Block directory name inside musicwave/blocks/.
 * @return {Object} Client registration metadata.
 */
function metadataEntry( dir ) {
	const meta = JSON.parse(
		fs.readFileSync(
			path.join( themeDir, 'blocks', dir, 'block.json' ),
			'utf8'
		)
	);
	return {
		name: meta.name,
		apiVersion: meta.apiVersion || 3,
		title: meta.title || '',
		description: meta.description || '',
		category: meta.category || 'music-wave',
		icon: meta.icon || 'format-audio',
		keywords: Array.isArray( meta.keywords ) ? meta.keywords : [],
		textdomain: meta.textdomain || 'musicwave',
		attributes: meta.attributes || {},
		supports: meta.supports || {},
		example: meta.example || {},
		styles: Array.isArray( meta.styles ) ? meta.styles : [],
	};
}

// The dynamic lane reads its dirs from musicwave_register_presentation_blocks();
// parse them out so this suite cannot drift from the registration site.
const themeSrcFiles = [ path.join( themeDir, 'functions.php' ) ];
for ( const sub of [
	'src',
	path.join( 'src', 'Blocks' ),
	path.join( 'src', 'Assets' ),
	path.join( 'src', 'Templates' ),
	path.join( 'src', 'Header' ),
	path.join( 'src', 'Navigation' ),
	path.join( 'src', 'Routing' ),
] ) {
	const dirPath = path.join( themeDir, sub );
	if ( fs.existsSync( dirPath ) ) {
		fs.readdirSync( dirPath )
			.filter( ( f ) => f.endsWith( '.php' ) )
			.forEach( ( f ) => themeSrcFiles.push( path.join( dirPath, f ) ) );
	}
}
const functionsSource = themeSrcFiles
	.map( ( f ) => fs.readFileSync( f, 'utf8' ) )
	.join( '\n' );
const dynamicDirs = [];
const dirPattern = /'dir'\s*=>\s*'([a-z0-9-]+)'/g;
let dirMatch = dirPattern.exec( functionsSource );
while ( null !== dirMatch ) {
	dynamicDirs.push( dirMatch[ 1 ] );
	dirMatch = dirPattern.exec( functionsSource );
}
const staticDirs = (
	functionsSource.match(
		/public function static_block_dirs\(\)\s*:\s*array\s*\{[^}]*?'([a-z0-9-]+)'/
	) || []
).slice( 1 );

check(
	dynamicDirs.length >= 5,
	'expected the theme dynamic block dirs to be parsed from functions.php'
);
check(
	staticDirs.length >= 1,
	'expected the theme static block dirs to be parsed from functions.php'
);

const presentationBlocks = [];
dynamicDirs.forEach( ( dir ) => {
	const entry = metadataEntry( dir );
	presentationBlocks.push( entry );
	const legacy = Object.assign( {}, entry, {
		name: 'musicwave/' + dir,
		supports: Object.assign( {}, entry.supports, { inserter: false } ),
	} );
	presentationBlocks.push( legacy );
} );
const staticBlocks = staticDirs.map( metadataEntry );

const registered = {};
const calls = {
	useBlockProps: [],
	useBlockPropsSave: [],
	innerBlocks: [],
	serverSideRender: [],
};

function createElement( type, props, ...children ) {
	if ( type && type.__isInnerBlocks ) {
		calls.innerBlocks.push( props || {} );
	}
	if ( 'ServerSideRenderComponent' === type ) {
		calls.serverSideRender.push( props || {} );
	}
	return { type, props: props || {}, children };
}

function useBlockProps( props ) {
	calls.useBlockProps.push( props || {} );
	return Object.assign( { 'data-lane': 'edit' }, props );
}
useBlockProps.save = function saveBlockProps( props ) {
	calls.useBlockPropsSave.push( props || {} );
	return Object.assign( { 'data-lane': 'save' }, props );
};

const sandbox = {
	console,
	window: {
		wp: {
			blocks: {
				getBlockType: ( name ) => registered[ name ],
				registerBlockType: ( name, config ) => {
					check(
						! registered[ name ],
						'the editor script must not register ' + name + ' twice'
					);
					registered[ name ] = config;
				},
			},
			element: { createElement, Fragment: 'Fragment' },
			blockEditor: {
				useBlockProps,
				InnerBlocks: Object.assign(
					{ __isInnerBlocks: true },
					{ Content: 'InnerBlocks.Content' }
				),
			},
			components: {
				Placeholder: 'Placeholder',
				PanelBody: 'PanelBody',
				TextControl: 'TextControl',
				TextareaControl: 'TextareaControl',
				SelectControl: 'SelectControl',
				ToggleControl: 'ToggleControl',
				RangeControl: 'RangeControl',
			},
			i18n: { __: ( text ) => text },
			serverSideRender: 'ServerSideRenderComponent',
		},
		musicwavePresentationBlocks: presentationBlocks,
		musicwavePresentationVariations: {},
		musicwaveStaticBlocks: staticBlocks,
	},
};

const scriptSource = fs.readFileSync( scriptFile, 'utf8' );
vm.runInNewContext( scriptSource, sandbox, { filename: 'editor-blocks.js' } );

// ---------------------------------------------------------------- inventory
const names = Object.keys( registered );
dynamicDirs.forEach( ( dir ) => {
	const entry = metadataEntry( dir );
	check(
		!! registered[ entry.name ],
		'the dynamic lane must register ' + entry.name
	);
	check(
		!! registered[ 'musicwave/' + dir ],
		'the dynamic lane must keep the legacy alias musicwave/' + dir
	);
	same(
		false,
		registered[ 'musicwave/' + dir ]
			? registered[ 'musicwave/' + dir ].supports.inserter
			: null,
		'the legacy alias musicwave/' +
			dir +
			' must stay hidden from the inserter'
	);
} );
staticDirs.forEach( ( dir ) => {
	const entry = metadataEntry( dir );
	check(
		!! registered[ entry.name ],
		'the static lane must register ' + entry.name
	);
	check(
		! registered[ 'musicwave/' + dir ],
		'the static lane must not invent a legacy alias for ' +
			entry.name +
			': nothing was ever saved under that name'
	);
} );

// Re-running the script must be a no-op: the getBlockType() guard is what keeps
// a second enqueue from clobbering a hydrated block type.
const countAfterFirstRun = names.length;
vm.runInNewContext( scriptSource, sandbox, { filename: 'editor-blocks.js' } );
same(
	countAfterFirstRun,
	Object.keys( registered ).length,
	're-running the editor script must not duplicate registrations'
);

// ------------------------------------------------------- dynamic lane (SSR)
dynamicDirs.forEach( ( dir ) => {
	const entry = metadataEntry( dir );
	const block = registered[ entry.name ];
	if ( ! block ) {
		return;
	}
	const ssrBefore = calls.serverSideRender.length;
	const innerBefore = calls.innerBlocks.length;
	block.edit( { attributes: {}, setAttributes() {}, isSelected: true } );
	check(
		calls.serverSideRender.length > ssrBefore,
		entry.name +
			' is PHP-rendered, so its editor preview must come from ServerSideRender'
	);
	same(
		innerBefore,
		calls.innerBlocks.length,
		entry.name + ' must not gain an InnerBlocks chrome'
	);
	same(
		null,
		block.save(),
		entry.name +
			' must store nothing: its markup comes from the render callback'
	);
	same(
		entry.title,
		block.title,
		entry.name + ' must carry its block.json title'
	);
	same(
		entry.keywords.length,
		block.keywords.length,
		entry.name + ' must carry its block.json keywords'
	);
} );

// ------------------------------------------- static lane (InnerBlocks, no SSR)
staticDirs.forEach( ( dir ) => {
	const entry = metadataEntry( dir );
	const block = registered[ entry.name ];
	if ( ! block ) {
		return;
	}

	same(
		entry.title,
		block.title,
		entry.name + ' must carry its block.json title'
	);
	same(
		entry.category,
		block.category,
		entry.name + ' must stay in the shared inserter category'
	);
	same(
		entry.attributes || {},
		block.attributes,
		entry.name + ' must carry its block.json attributes'
	);

	const ssrBefore = calls.serverSideRender.length;
	const propsBefore = calls.useBlockProps.length;
	const innerBefore = calls.innerBlocks.length;
	const edited = block.edit( {
		attributes: {},
		setAttributes() {},
		isSelected: true,
	} );

	same(
		ssrBefore,
		calls.serverSideRender.length,
		entry.name +
			' must not use ServerSideRender: its children are real blocks'
	);
	check(
		calls.useBlockProps.length > propsBefore,
		entry.name + ' must build its editor shell through useBlockProps()'
	);
	same(
		'mw-section-head',
		calls.useBlockProps[ calls.useBlockProps.length - 1 ].className,
		entry.name + ' must style its shell with the editorial component class'
	);
	same(
		'div',
		edited.type,
		entry.name + ' must render a single shell element in the editor'
	);

	check(
		calls.innerBlocks.length > innerBefore,
		entry.name + ' must render its children through InnerBlocks'
	);
	const innerProps = calls.innerBlocks[ calls.innerBlocks.length - 1 ];
	same(
		false,
		innerProps.templateLock,
		entry.name +
			' must leave the template unlocked so children can be added, removed and reordered'
	);
	// The seeded structure is the contract: only .mw-section-head__text stacks
	// its lines vertically, because the shell itself is a flex row that expects
	// siblings such as the __rule. Placeholders are asserted as present, not as
	// wording, so translating them does not break the structure test.
	same(
		1,
		innerProps.template.length,
		entry.name +
			' must seed a single text stack; the shell is the block itself'
	);
	const seededGroup = innerProps.template[ 0 ];
	same(
		'core/group',
		seededGroup[ 0 ],
		entry.name + ' must seed its text stack as a real core/group child'
	);
	same(
		{ className: 'mw-section-head__text', layout: { type: 'default' } },
		seededGroup[ 1 ],
		entry.name +
			' must seed the mw-section-head__text group that stacks the header lines'
	);
	same(
		[ 'core/paragraph', 'core/heading', 'core/paragraph' ],
		seededGroup[ 2 ].map( ( seeded ) => seeded[ 0 ] ),
		entry.name +
			' must seed eyebrow, title and description in the order the PHP shelf header uses'
	);
	same(
		'mw-eyebrow',
		seededGroup[ 2 ][ 0 ][ 1 ].className,
		entry.name + ' must seed the eyebrow with the mw-eyebrow kicker class'
	);
	same(
		2,
		seededGroup[ 2 ][ 1 ][ 1 ].level,
		entry.name +
			' must seed a level-2 heading, the level .mw-section-head h2 styles'
	);
	check(
		seededGroup[ 2 ].every(
			( seeded ) =>
				'string' === typeof seeded[ 1 ].placeholder &&
				seeded[ 1 ].placeholder.length > 0
		),
		entry.name + ' must give every seeded child a translated placeholder'
	);

	const savePropsBefore = calls.useBlockPropsSave.length;
	const saved = block.save();
	check(
		calls.useBlockPropsSave.length > savePropsBefore,
		entry.name + ' must build its stored shell through useBlockProps.save()'
	);
	same(
		'mw-section-head',
		calls.useBlockPropsSave[ calls.useBlockPropsSave.length - 1 ].className,
		entry.name + ' must store the editorial component class'
	);
	same(
		'div',
		saved.type,
		entry.name + ' must store a single shell element'
	);
	same(
		'InnerBlocks.Content',
		saved.children[ 0 ] && saved.children[ 0 ].type
			? saved.children[ 0 ].type
			: null,
		entry.name + ' must store its children from InnerBlocks.Content'
	);
	same(
		saved.props.className,
		edited.props.className,
		entry.name +
			' must store the same shell it edits, or block validation will report invalid content'
	);
} );

// Verify upgraded Inspector controls & style-vs-layout independence on shelf, slider, and breadcrumb:
const shelfBlock = registered[ 'music-wave/release-shelf' ];
const shelfUpdates = [];
const shelfTree = JSON.stringify(
	shelfBlock.edit( {
		attributes: {
			layout: 'feature',
			className: 'is-style-vinyl custom-cls',
			styleVariant: '',
		},
		setAttributes: ( next ) => shelfUpdates.push( next ),
	} )
);
check(
	shelfTree.includes( 'عنوان بخش معرفی' ),
	'Shelf feature layout must expose asideTitle control in Inspector'
);
check(
	shelfTree.includes( 'تیرگی لایه رویی' ),
	'Shelf feature layout must expose overlay control in Inspector'
);
check(
	shelfTree.includes( 'عرض ستون کناری (پیکسل)' ),
	'Shelf feature layout must expose sideColumnWidth control in Inspector'
);
check(
	shelfTree.includes( 'تب‌های فیلتر' ),
	'Shelf filterTabs must expose clear filter tabs control in Inspector'
);

const sliderBlock = registered[ 'music-wave/release-slider' ];
const sliderTree = JSON.stringify(
	sliderBlock.edit( {
		attributes: { imageShape: 'square', className: '', styleVariant: '' },
		setAttributes: () => {},
	} )
);
check(
	sliderTree.includes( 'شکل تصویر' ),
	'Release slider must expose imageShape control in Inspector'
);
check(
	sliderTree.includes( 'تب‌های فیلتر' ),
	'Release slider must expose filterTabs control in Inspector'
);

const breadcrumbBlock = registered[ 'music-wave/breadcrumb' ];
const breadcrumbTree = JSON.stringify(
	breadcrumbBlock.edit( {
		attributes: {
			separator: 'chevron',
			showHomeIcon: true,
			showCurrent: true,
		},
		setAttributes: () => {},
	} )
);
check(
	breadcrumbTree.includes( 'نمونه مسیر راهنما (Breadcrumb)' ),
	'Breadcrumb block must display live preview guidance in Inspector'
);
check(
	breadcrumbTree.includes( 'جداکننده مسیر' ),
	'Breadcrumb block must expose separator control in Inspector'
);

// Full Inspector <-> block.json <-> enum schema parity audit across every theme block:
function walkTree( node, visit ) {
	if ( ! node || 'object' !== typeof node ) {
		return;
	}
	visit( node );
	if ( Array.isArray( node.children ) ) {
		node.children.forEach( ( child ) => walkTree( child, visit ) );
	}
}
presentationBlocks
	.filter( ( item ) => item.name.startsWith( 'music-wave/' ) )
	.forEach( ( meta ) => {
		const def = registered[ meta.name ];
		[
			{},
			{ layout: 'feature', source: 'playlists', ctaStyle: 'solid' },
			{ layout: 'slider' },
		].forEach( ( state ) => {
			let lastPatch = {};
			const written = new Set();
			const tree = def.edit( {
				attributes: Object.assign( {}, state ),
				setAttributes: ( patch ) => {
					lastPatch = patch || {};
					Object.keys( lastPatch ).forEach( ( k ) =>
						written.add( k )
					);
				},
			} );
			walkTree( tree, ( n ) => {
				if ( n.props && 'function' === typeof n.props.onChange ) {
					lastPatch = {};
					n.props.onChange(
						'input' === n.type
							? { target: { value: '#ff0000' } }
							: 'test'
					);
					const keys = Object.keys( lastPatch );
					if (
						1 === keys.length &&
						Array.isArray( n.props.options )
					) {
						const schema = meta.attributes[ keys[ 0 ] ];
						if ( schema && Array.isArray( schema.enum ) ) {
							n.props.options.forEach( ( opt ) => {
								check(
									schema.enum.includes( opt.value ),
									meta.name +
										' SelectControl option "' +
										opt.value +
										'" for "' +
										keys[ 0 ] +
										'" must exist in block.json enum'
								);
							} );
						}
					}
				}
				if ( n.props && 'function' === typeof n.props.onClick ) {
					n.props.onClick();
				}
			} );
			written.forEach( ( attrKey ) => {
				if ( 'className' !== attrKey ) {
					check(
						Object.prototype.hasOwnProperty.call(
							meta.attributes,
							attrKey
						),
						meta.name +
							' Inspector control writes "' +
							attrKey +
							'" which must be declared in block.json'
					);
				}
			} );
		} );
	} );

if ( failures > 0 ) {
	console.error( '\n' + failures + ' editor lane assertion(s) failed.' );
	process.exit( 1 );
}

console.log(
	'Editor lanes OK (' +
		countAfterFirstRun +
		' registrations: ' +
		dynamicDirs.length +
		' PHP-rendered + ' +
		dynamicDirs.length +
		' legacy aliases + ' +
		staticDirs.length +
		' static InnerBlocks).'
);
