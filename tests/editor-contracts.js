/** Offline editor registration and metadata UI contracts (no WordPress required). */
'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );
const { JSDOM } = require( 'jsdom' );
const root = path.join( __dirname, '..' );
const read = ( file ) => fs.readFileSync( path.join( root, file ), 'utf8' );

function editorContracts() {
	const metadata = JSON.parse(
		read( 'music-wave-core/blocks/release-meta/block.json' )
	);
	const sentinel = { customContext: 'releaseId' };
	const registered = {
		[ metadata.name ]: { ...metadata, providesContext: sentinel },
	};
	const variations = [];
	let wrapperCalls = 0;
	const createElement = ( type, props, ...children ) => ( {
		type,
		props: props || {},
		children: children.flat( Infinity ),
	} );
	const wp = {
		blocks: {
			getBlockType: ( name ) => registered[ name ],
			unregisterBlockType: ( name ) => delete registered[ name ],
			registerBlockType: ( name, settings ) => {
				registered[ name ] = settings;
			},
			registerBlockVariation: ( name, variation ) =>
				variations.push( { name, ...variation } ),
		},
		element: { createElement, Fragment: 'Fragment' },
		blockEditor: {
			InspectorControls: 'InspectorControls',
			useBlockProps: ( props ) => {
				wrapperCalls++;
				return { ...props, 'data-native-props': true };
			},
		},
		components: new Proxy( {}, { get: ( obj, key ) => key } ),
		i18n: { __: ( value ) => value },
		serverSideRender: 'ServerSideRender',
	};
	vm.runInNewContext( read( 'music-wave-core/assets/blocks.js' ), {
		window: { wp, musicWaveDynamicBlocks: [ metadata ] },
	} );
	assert.equal(
		registered[ metadata.name ].providesContext,
		sentinel,
		'Unknown server metadata must survive registration.'
	);
	assert.equal(
		variations.length,
		0,
		'Duplicate single-fact variations are consolidated into unified release-meta.'
	);
	assert.equal(
		metadata.attributes.compact.default,
		true,
		'Compact dot-separated mode is the primary default.'
	);
	for ( const removed of [
		'showLibraryButton',
		'showTaxonomyChips',
		'showActions',
		'linkTerms',
	] ) {
		assert.equal(
			metadata.attributes[ removed ],
			undefined,
			`${ removed } bloat control must be removed.`
		);
	}
	assert.equal(
		fs.existsSync(
			path.join( root, 'music-wave-core/blocks/dashboard-edit' )
		),
		false,
		'Duplicate dashboard-edit block directory must not exist.'
	);
	const accountMeta = JSON.parse(
		read( 'music-wave-core/blocks/dashboard-account/block.json' )
	);
	registered[ accountMeta.name ] = { ...accountMeta };
	vm.runInNewContext( read( 'music-wave-core/assets/blocks.js' ), {
		window: { wp, musicWaveDynamicBlocks: [ metadata, accountMeta ] },
	} );
	assert.equal(
		registered[ 'music-wave/dashboard-edit' ].supports.inserter,
		false,
		'Legacy dashboard-edit alias stays hidden from inserter.'
	);
	assert.equal(
		registered[ 'music-wave/dashboard-edit' ].transforms.to[ 0 ]
			.blocks[ 0 ],
		'music-wave/dashboard-account'
	);
	wrapperCalls = 0;
	const settings = registered[ metadata.name ];
	const tree = settings.edit( {
		attributes: {
			compact: true,
			showLabels: true,
			showCatalogNumber: true,
		},
		context: { postId: 123, postType: 'mw_release' },
		setAttributes: () =>
			assert.fail( 'Preview context must not persist attributes.' ),
	} );
	function find( node, predicate ) {
		if ( ! node || typeof node !== 'object' ) {
			return null;
		}
		if ( predicate( node ) ) {
			return node;
		}
		for ( const child of node.children || [] ) {
			const found = find( child, predicate );
			if ( found ) {
				return found;
			}
		}
		return null;
	}
	assert.equal(
		wrapperCalls,
		1,
		'Native block props are called unconditionally.'
	);
	assert.ok(
		find( tree, ( node ) => node.props[ 'data-native-props' ] ),
		'Native selection/style props reach the canvas wrapper.'
	);
	const preview = find( tree, ( node ) => node.type === 'ServerSideRender' );
	assert.equal( preview.props.attributes.releaseId, 123 );
	assert.equal( preview.props.urlQueryArgs.post_id, 123 );
	assert.equal(
		preview.props.skipBlockSupportAttributes,
		true,
		'Preview must not apply spacing twice.'
	);
	assert.equal(
		settings.save(),
		null,
		'Secure dynamic rendering stays in PHP.'
	);
}

function actionButtonsSearchArtistAndTracklistContracts() {
	const coreBlocksJs = read( 'music-wave-core/assets/blocks.js' );
	const themeEditorJs = read( 'musicwave/assets/editor-blocks.js' );

	// 1. Confusing manual "پیش‌نمایش انتشار" release ID picker is completely removed.
	assert.equal(
		coreBlocksJs.includes( 'پیش‌نمایش انتشار' ),
		false,
		'Confusing manual "پیش‌نمایش انتشار" selector must never appear in editor controls.'
	);
	assert.equal(
		coreBlocksJs.includes( 'ReleaseSelectorControl' ),
		false,
		'Legacy ReleaseSelectorControl dead code must be completely removed.'
	);

	// 2. Clean single custom icon input + actionButtonAppearanceGroup (zero bloated multi-icon grid / audio mode / playStyle).
	assert.equal(
		coreBlocksJs.includes( 'mw-icon-picker' ),
		false,
		'Bloated multi-icon grid picker must be completely removed from blocks.js.'
	);
	assert.equal(
		coreBlocksJs.includes( 'emptyStateCopy' ) ||
			coreBlocksJs.includes( 'mw-block-editor-empty' ),
		false,
		'Descriptive empty-state placeholders must be completely removed from blocks.js.'
	);
	assert.equal(
		coreBlocksJs.includes( 'حالت پخش صدا' ) ||
			coreBlocksJs.includes( 'سبک دکمه پخش ابتدای ردیف' ),
		false,
		'Redundant audio mode and row lead playStyle controls must be completely removed.'
	);
	assert.equal(
		coreBlocksJs.includes( "'customIcon'" ) &&
			coreBlocksJs.includes( 'actionButtonAppearanceGroup' ),
		true,
		'Core editor script must expose single clean customIcon field + unified actionButtonAppearanceGroup.'
	);

	// 3. Preview Player metadata exposes clean automatic audio playback + unified styling without reserved `style` collision.
	const previewMeta = JSON.parse(
		read( 'music-wave-core/blocks/preview-player/block.json' )
	);
	assert.equal( previewMeta.attributes.buttonStyle.default, 'icon' );
	assert.equal(
		previewMeta.attributes.style,
		undefined,
		'block.json must never declare reserved WordPress support attribute `style`!'
	);
	assert.equal(
		previewMeta.attributes.mode,
		undefined,
		'preview-player audio mode must be automatic, not a manual block attribute.'
	);
	assert.equal( previewMeta.attributes.variant.default, 'accent' );
	assert.equal( previewMeta.attributes.size.default, 'lg' );
	assert.equal( previewMeta.attributes.shape.default, 'pill' );
	assert.equal( previewMeta.attributes.icon.default, '' );

	const createElement = ( type, props, ...children ) => ( {
		type,
		props: props || {},
		children: children.flat( Infinity ),
	} );
	const allBlockMetas = [];
	for ( const dir of [
		'music-wave-core/blocks',
		'music-wave-vip/blocks',
		'musicwave/blocks',
	] ) {
		if ( ! fs.existsSync( path.join( root, dir ) ) ) {
			continue;
		}
		for ( const sub of fs.readdirSync( path.join( root, dir ) ) ) {
			const file = path.join( root, dir, sub, 'block.json' );
			if ( fs.existsSync( file ) ) {
				const parsedMeta = JSON.parse(
					fs.readFileSync( file, 'utf8' )
				);
				assert.equal(
					parsedMeta.attributes && parsedMeta.attributes.style,
					undefined,
					`${ file }: must NEVER declare reserved WP_Block_Supports attribute 'style' (causes HTTP 400 rest_invalid_param in Site Editor)!`
				);
				assert.equal(
					parsedMeta.attributes && parsedMeta.attributes.className,
					undefined,
					`${ file }: must NEVER duplicate WordPress customClassName attribute 'className'!`
				);
				allBlockMetas.push( parsedMeta );
			}
		}
	}
	const reg = {};
	for ( const meta of allBlockMetas ) {
		reg[ meta.name ] = { ...meta };
	}
	const wpMock = {
		blocks: {
			getBlockType: ( name ) => reg[ name ],
			unregisterBlockType: ( name ) => delete reg[ name ],
			registerBlockType: ( name, settings ) => {
				reg[ name ] = settings;
			},
			registerBlockVariation: () => {},
		},
		element: {
			createElement,
			Fragment: 'Fragment',
			RawHTML: 'RawHTML',
			useState: ( init ) => [ init, () => {} ],
			useEffect: () => {},
			useMemo: ( fn ) => fn(),
		},
		blockEditor: {
			InspectorControls: 'InspectorControls',
			BlockControls: 'BlockControls',
			RichText: 'RichText',
			useBlockProps: ( props ) => ( { ...props } ),
		},
		components: new Proxy( {}, { get: ( _obj, key ) => key } ),
		i18n: { __: ( value ) => value, sprintf: ( str ) => str },
		data: { useSelect: () => [] },
		serverSideRender: 'ServerSideRender',
	};
	vm.runInNewContext( coreBlocksJs, {
		window: {
			wp: wpMock,
			musicWaveDynamicBlocks: allBlockMetas.filter( ( b ) =>
				b.name.startsWith( 'music-wave/' )
			),
		},
	} );
	vm.runInNewContext( themeEditorJs, {
		window: { wp: wpMock },
	} );

	function walkTree( node, visit ) {
		if ( ! node || typeof node !== 'object' ) {
			return;
		}
		visit( node );
		for ( const child of node.children || [] ) {
			walkTree( child, visit );
		}
	}
	function findNode( node, predicate ) {
		if ( ! node || typeof node !== 'object' ) {
			return null;
		}
		if ( predicate( node ) ) {
			return node;
		}
		for ( const child of node.children || [] ) {
			const found = findNode( child, predicate );
			if ( found ) {
				return found;
			}
		}
		return null;
	}

	let pickedIcon = null;
	const previewTree = reg[ previewMeta.name ].edit( {
		attributes: { style: 'icon', icon: '' },
		context: {
			postId: 'musicwave//single-mw_release',
			postType: 'wp_template',
		},
		setAttributes: ( patch ) => {
			pickedIcon = patch.icon;
		},
	} );
	const ssrNode = findNode(
		previewTree,
		( n ) => n.type === 'ServerSideRender'
	);
	assert.equal(
		ssrNode.props.urlQueryArgs,
		undefined,
		'Site Editor template string postId ("musicwave//single-mw_release") must NEVER be sent as post_id to WP REST block-renderer!'
	);
	assert.equal(
		ssrNode.props.attributes.style,
		undefined,
		'Legacy string `attributes.style` saved in DB templates must NEVER be sent to WP REST block-renderer!'
	);
	assert.equal(
		ssrNode.props.attributes.buttonStyle,
		'icon',
		'Legacy string `attributes.style` saved in DB templates must auto-remap into `buttonStyle` on first render!'
	);
	const customIconInput = findNode(
		previewTree,
		( n ) =>
			n.type === 'TextControl' &&
			n.props &&
			typeof n.props.placeholder === 'string' &&
			n.props.placeholder.includes( '<svg>' )
	);
	assert.ok(
		customIconInput,
		'Preview player Inspector must expose a clean single TextControl for custom icon class/SVG.'
	);
	customIconInput.props.onChange( 'dashicons dashicons-controls-play' );
	assert.equal(
		pickedIcon,
		'dashicons dashicons-controls-play',
		'Typing into customIcon field must update the block icon attribute.'
	);

	// Every single Inspector control written by setAttributes() MUST exist in block.json (zero HTTP 400 SSR errors!).
	for ( const meta of allBlockMetas ) {
		const def = reg[ meta.name ];
		if ( ! def || typeof def.edit !== 'function' ) {
			continue;
		}
		const schemaKeys = Object.keys( meta.attributes || {} );
		const writtenKeys = new Set();
		const tree = def.edit( {
			attributes: {
				mode: 'list',
				style: 'button',
				layout: 'feature',
				desktopMode: 'bar',
				tabletMode: 'compact',
				mobileMode: 'popup',
			},
			context: { postId: 1, postType: 'mw_release' },
			setAttributes: ( patch ) =>
				Object.keys( patch ).forEach( ( k ) => writtenKeys.add( k ) ),
		} );
		walkTree( tree, ( n ) => {
			if ( n.props && typeof n.props.onChange === 'function' ) {
				try {
					n.props.onChange( 'test' );
				} catch {}
			}
			if ( n.props && typeof n.props.onClick === 'function' ) {
				try {
					n.props.onClick();
				} catch {}
			}
		} );
		for ( const key of writtenKeys ) {
			if ( key === 'className' ) {
				continue;
			}
			assert.equal(
				schemaKeys.includes( key ),
				true,
				`${ meta.name } Inspector writes unregistered attribute "${ key }" missing from block.json!`
			);
		}
	}

	// 4. Header Search metadata & editor script expose clean responsive modes + native Styles without bloat.
	const searchMeta = JSON.parse(
		read( 'musicwave/blocks/header-search/block.json' )
	);
	for ( const attr of [
		'desktopMode',
		'tabletMode',
		'mobileMode',
		'maxWidth',
		'enableShortcut',
		'showScopeTabs',
		'showRecentSearches',
		'showVoiceSearch',
		'showFooterHints',
	] ) {
		assert.notEqual(
			searchMeta.attributes[ attr ],
			undefined,
			`header-search must define ${ attr } attribute.`
		);
	}
	const headerSearchSlice = themeEditorJs.slice(
		themeEditorJs.indexOf( 'function headerSearchInspector' ),
		themeEditorJs.indexOf( 'function queryFilteringPanel' )
	);
	for ( const removedBloat of [
		'icon',
		'shape',
		'size',
		'triggerVariant',
		'showTriggerLabel',
	] ) {
		assert.equal(
			searchMeta.attributes[ removedBloat ],
			undefined,
			`header-search must NOT define bloated ${ removedBloat } attribute.`
		);
		assert.equal(
			headerSearchSlice.includes( `${ removedBloat }:` ),
			false,
			`headerSearchInspector must NOT write removed ${ removedBloat } attribute.`
		);
	}
	assert.equal( searchMeta.attributes.mobileMode.default, 'popup' );
	assert.equal(
		searchMeta.supports.color.gradients,
		true,
		'header-search must support native color gradients.'
	);
	assert.equal(
		themeEditorJs.includes( 'desktopMode' ) &&
			themeEditorJs.includes( 'tabletMode' ) &&
			themeEditorJs.includes( 'mobileMode' ),
		true,
		'Theme editor script must wire responsive per-breakpoint search controls.'
	);

	// 5. Artist Profile metadata exposes unified Inspector attributes + followIcon + style variations.
	const artistMeta = JSON.parse(
		read( 'music-wave-core/blocks/artist-profile/block.json' )
	);
	for ( const attr of [
		'compact',
		'layout',
		'showImage',
		'showTitle',
		'showBio',
		'showLink',
		'showLibraryButton',
		'libraryButtonStyle',
		'followIcon',
		'imageSize',
		'imageShape',
		'showReleaseCount',
	] ) {
		assert.notEqual(
			artistMeta.attributes[ attr ],
			undefined,
			`artist-profile must define ${ attr } attribute.`
		);
	}
	assert.deepEqual(
		artistMeta.styles.map( ( s ) => s.name ),
		[ 'editorial', 'minimal', 'glass' ]
	);

	// 6. Collection List metadata exposes the quality selector and the unified play/download action controls.
	const collectionMeta = JSON.parse(
		read( 'music-wave-core/blocks/collection-list/block.json' )
	);
	assert.equal(
		collectionMeta.attributes.playStyle,
		undefined,
		'collection-list must not expose redundant playStyle attribute.'
	);
	for ( const attr of [
		'actionStyle',
		'playIcon',
		'downloadIcon',
		'showDownloadQuality',
		'density',
		'actionsVisibility',
		'showHeader',
		'showHeading',
		'showArtist',
		'showBpm',
		'showQuality',
		'showDuration',
		'showDownload',
		'groupByDisc',
	] ) {
		assert.notEqual(
			collectionMeta.attributes[ attr ],
			undefined,
			`collection-list must define ${ attr } attribute.`
		);
	}
	for ( const attr of [
		'queueIcon',
		'libraryIcon',
		'showQueueAction',
		'showLibraryAction',
	] ) {
		assert.equal(
			collectionMeta.attributes[ attr ],
			undefined,
			`collection-list must not expose per-row ${ attr } attribute (queue/library row actions were removed).`
		);
	}
}

async function metadataUI( endpoint, plain, fail = false ) {
	const dom = new JSDOM(
		'<div class="mw-metadata-lookup" data-post-id="7" data-release-types=\'["track"]\'></div>',
		{
			url: 'https://example.test/sub/wp-admin/post.php?post=7&action=edit',
			runScripts: 'outside-only',
		}
	);
	const { window } = dom;
	const calls = [];
	window.musicWaveMetadataLookup = { root: endpoint, nonce: 'test-nonce' };
	window.fetch = async ( url, options ) => {
		calls.push( { url: new URL( url ), options } );
		return {
			ok: true,
			json: async () =>
				options.method === 'GET'
					? fail
						? {
								success: false,
								code: 'provider_unavailable',
								message: 'Provider connection failed',
						  }
						: {
								success: true,
								results: [
									{
										title: '<img src=x onerror=alert(1)>',
										artist: 'Artist',
										provider: 'musicbrainz',
										reference_id: 'one',
									},
								],
						  }
					: {
							success: true,
							fields: {},
							warnings: [],
							message: 'Saved',
					  },
		};
	};
	window.eval( read( 'music-wave-core/assets/metadata-lookup.js' ) );
	await new Promise( ( resolve ) => window.setTimeout( resolve, 0 ) );
	const input = window.document.querySelector( '.mw-metadata-lookup__input' );
	input.value = 'A & B + موسیقی';
	const search = window.document.querySelector(
		'.mw-metadata-lookup__search'
	);
	search.click();
	input.dispatchEvent(
		new window.KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } )
	);
	await new Promise( ( resolve ) => window.setTimeout( resolve, 0 ) );
	assert.equal(
		calls.length,
		1,
		'Enter while busy must not duplicate provider requests.'
	);
	assert.equal( calls[ 0 ].url.searchParams.get( 'q' ), input.value );
	assert.equal(
		calls[ 0 ].url.searchParams.get( 'release_types[]' ),
		'track'
	);
	assert.equal( calls[ 0 ].options.headers[ 'X-WP-Nonce' ], 'test-nonce' );
	assert.equal( calls[ 0 ].options.credentials, 'same-origin' );
	if ( plain ) {
		assert.equal(
			calls[ 0 ].url.searchParams.get( 'rest_route' ),
			'/music-wave/v1/metadata-lookup'
		);
		assert.equal( calls[ 0 ].url.searchParams.get( 'lang' ), 'fa' );
	} else {
		assert.equal(
			calls[ 0 ].url.pathname,
			'/sub/wp-json/music-wave/v1/metadata-lookup'
		);
	}
	if ( fail ) {
		assert.equal(
			window.document.querySelector( '.mw-metadata-lookup__status' )
				.textContent,
			'Provider connection failed'
		);
		assert.equal(
			search.disabled,
			false,
			'Provider failure allows retry.'
		);
	} else {
		assert.equal(
			window.document.querySelector( '.mw-metadata-lookup__title img' ),
			null,
			'Provider strings must never become HTML.'
		);
		window.document.querySelector( '.mw-metadata-lookup__apply' ).click();
		await new Promise( ( resolve ) => window.setTimeout( resolve, 0 ) );
		assert.equal( calls.length, 2 );
		assert.equal( calls[ 1 ].options.method, 'POST' );
		assert.equal( JSON.parse( calls[ 1 ].options.body ).post_id, 7 );
		assert.equal(
			plain
				? calls[ 1 ].url.searchParams.get( 'rest_route' )
				: calls[ 1 ].url.pathname,
			plain
				? '/music-wave/v1/metadata-lookup/apply'
				: '/sub/wp-json/music-wave/v1/metadata-lookup/apply'
		);
	}
	window.close();
}

( async () => {
	editorContracts();
	actionButtonsSearchArtistAndTracklistContracts();
	await metadataUI(
		'https://example.test/sub/wp-json/music-wave/v1/metadata-lookup',
		false
	);
	await metadataUI(
		'https://example.test/sub/index.php?rest_route=%2Fmusic-wave%2Fv1%2Fmetadata-lookup&lang=fa',
		true
	);
	await metadataUI(
		'https://example.test/sub/wp-json/music-wave/v1/metadata-lookup',
		false,
		true
	);
	console.log(
		'Editor contracts passed: native facts, context, styles, REST routing, safe rendering and retry.'
	);
} )().catch( ( error ) => {
	console.error( error );
	process.exitCode = 1;
} );
