/**
 * Stage D E2E: music-wave/section-head, the theme's only static InnerBlocks
 * block.
 *
 * A static block's risks are different from a dynamic block's: its frontend
 * markup is whatever save() stored, so the failure modes are (1) block
 * validation breaking on reload, (2) children that cannot be selected or moved,
 * and (3) a Styles-panel look that reaches the editor but not the frontend.
 * Each scenario below targets one of those, plus Persian inserter discovery.
 *
 * Structural contracts (registration, lane separation, block.json metadata,
 * CSS spelling parity) are asserted statically in tests/template-integrity.php;
 * this suite only covers what needs a running editor.
 *
 * Runs against the disposable staging install (docs/staging.md).
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const BLOCK = 'music-wave/section-head';
const HEADING_TEXT = 'وینیل‌هایی که این هفته گذاشته‌ایم';

/**
 * Insert the section head and return its stored block tree.
 *
 * @param {Object} editor Editor utils fixture.
 * @return {Object} The inserted block, including its innerBlocks.
 */
async function insertSectionHead( editor ) {
	await editor.insertBlock( { name: BLOCK } );
	const blocks = await editor.getBlocks();
	const inserted = blocks.find( ( block ) => BLOCK === block.name );
	expect(
		inserted,
		'the section head must be insertable from the inserter'
	).toBeTruthy();
	return inserted;
}

/**
 * The block's stored comment delimiters: a static container must be paired,
 * because a self-closing comment would store no editable children at all.
 */
test.describe( 'music-wave/section-head (static InnerBlocks pilot)', () => {
	test( 'seeds the editorial structure and stores it paired', async ( {
		admin,
		editor,
	} ) => {
		await admin.createNewPost( {
			postType: 'page',
			title: 'E2E section-head structure',
		} );
		const inserted = await insertSectionHead( editor );

		// The template seeds one core/group shell holding eyebrow, title and
		// description — real core blocks, not wrapper markup the theme owns.
		expect( inserted.innerBlocks.map( ( block ) => block.name ) ).toEqual( [
			'core/group',
		] );
		const textGroup = inserted.innerBlocks[ 0 ];
		expect( textGroup.attributes.className ).toContain(
			'mw-section-head__text'
		);
		expect( textGroup.innerBlocks.map( ( block ) => block.name ) ).toEqual(
			[ 'core/paragraph', 'core/heading', 'core/paragraph' ]
		);
		expect( textGroup.innerBlocks[ 0 ].attributes.className ).toContain(
			'mw-eyebrow'
		);
		expect( textGroup.innerBlocks[ 1 ].attributes.level ).toBe( 2 );

		const content = await editor.getEditedPostContent();
		expect( content ).toContain( '<!-- wp:music-wave/section-head' );
		expect( content ).toContain( '<!-- /wp:music-wave/section-head -->' );
		expect( content ).toContain( 'mw-section-head__text' );
		// The shell class comes from save(), so the frontend stylesheet applies
		// without any PHP renderer being involved.
		expect( content ).toContain( 'mw-section-head' );
		// A static container stores no ServerSideRender placeholder markup.
		expect( content ).not.toContain( 'data-server-rendered' );
	} );

	test( 'children stay individually selectable, editable and movable', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			postType: 'page',
			title: 'E2E section-head composability',
		} );
		await insertSectionHead( editor );

		// Select a child directly: the whole point of the lane is that the
		// header's parts are real blocks rather than attributes on a wrapper.
		const title = editor.canvas.getByRole( 'heading', { level: 2 } );
		await title.click();
		await page.keyboard.type( HEADING_TEXT );
		await expect( title ).toContainText( HEADING_TEXT );

		// templateLock false must expose the move controls; a locked template
		// hides them, which is how an editor loses the ability to reorder.
		await expect(
			editor.canvas.getByRole( 'button', { name: 'Move up' } )
		).toBeVisible();

		const content = await editor.getEditedPostContent();
		expect( content ).toContain( HEADING_TEXT );

		// Only the edited child changed: the container stores no duplicated copy
		// of its children's text.
		const blocks = await editor.getBlocks();
		const stored = blocks.find( ( block ) => BLOCK === block.name );
		expect(
			stored.innerBlocks[ 0 ].innerBlocks[ 1 ].attributes.content
		).toContain( HEADING_TEXT );
	} );

	test( 'a Styles panel look reaches the stored markup and the frontend', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			postType: 'page',
			title: 'E2E section-head styles',
		} );
		await insertSectionHead( editor );

		await editor.canvas.getByRole( 'heading', { level: 2 } ).click();
		await page.keyboard.type( HEADING_TEXT );

		const sidebar = page.locator( '.edit-post-sidebar' );
		if ( ! ( await sidebar.isVisible().catch( () => false ) ) ) {
			await page.getByRole( 'button', { name: 'Settings' } ).click();
			await sidebar.waitFor();
		}
		// Styles panel → the registered center/stack/invert block styles. The
		// labels come from musicwave_section_head_styles(), which ships Persian
		// source strings with no en_US translation, so the panel reads Persian
		// even on an English staging install.
		await sidebar.getByRole( 'button', { name: 'Styles' } ).click();
		await sidebar.getByRole( 'button', { name: 'وسط‌چین' } ).click();

		const content = await editor.getEditedPostContent();
		expect( content ).toContain( 'is-style-center' );

		const permalink = await editor.publishPost();
		await page.goto( permalink );

		const rendered = page.locator( '.mw-section-head' ).first();
		await expect( rendered ).toBeVisible();
		await expect( rendered ).toHaveClass( /is-style-center/ );
		await expect( rendered ).toContainText( HEADING_TEXT );
		await expect( page.locator( 'body' ) ).not.toContainText(
			'Fatal error'
		);
	} );

	test( 'the stored block still validates when the draft is reopened', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			postType: 'page',
			title: 'E2E section-head validation',
		} );
		await insertSectionHead( editor );

		await editor.canvas.getByRole( 'heading', { level: 2 } ).click();
		await page.keyboard.type( HEADING_TEXT );
		await editor.saveDraft();
		await page.reload();

		// A save()/edit() mismatch shows up here as the invalid-content notice;
		// for a static block that is the single most likely regression.
		await expect(
			page.getByText( /block contains unexpected or invalid content/i )
		).toHaveCount( 0 );

		const blocks = await editor.getBlocks();
		expect( blocks.map( ( block ) => block.name ) ).toContain( BLOCK );
		await expect(
			editor.canvas.getByRole( 'heading', { level: 2 } )
		).toContainText( HEADING_TEXT );
	} );

	test( 'is searchable in the block inserter by Persian keywords', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			postType: 'page',
			title: 'E2E section-head inserter',
		} );

		await page
			.getByRole( 'button', { name: 'Toggle block inserter' } )
			.click();
		const search = page.getByRole( 'searchbox' ).first();

		await search.fill( 'سرصفحه' );
		await expect(
			page.getByRole( 'option', { name: /سرصفحهٔ بخش MusicWave/ } )
		).toBeVisible();

		await page.keyboard.press( 'Escape' );
		await editor.insertBlock( { name: BLOCK } );
		const blocks = await editor.getBlocks();
		expect( blocks.map( ( block ) => block.name ) ).toContain( BLOCK );
	} );
} );
