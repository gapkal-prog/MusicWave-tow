/**
 * Editor E2E suite: every MusicWave dynamic block must be insertable,
 * expose at least one working inspector setting, save cleanly, and render
 * on the front end whenever it can operate without release context.
 *
 * Runs against the disposable staging install (docs/staging.md).
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * One scenario per block:
 * - panel/action drive a single inspector change.
 * - expectContent is the attribute fragment that must appear in the saved markup.
 * - frontendSelector, when set, must be visible on the published page.
 */
const SCENARIOS = [
	{
		name: 'music-wave/release-meta',
		panel: 'Fields',
		action: { type: 'toggle', label: 'Show catalog number' },
		expectContent: '"showCatalogNumber":false',
	},
	{
		name: 'music-wave/access-panel',
		panel: 'Display',
		action: { type: 'toggle', label: 'Show the granted state' },
		expectContent: '"showWhenGranted":false',
	},
	{
		name: 'music-wave/release-credits',
		panel: 'Content',
		action: { type: 'toggle', label: 'Show credit roles' },
		expectContent: '"showRole":false',
	},
	{
		name: 'music-wave/collection-list',
		panel: 'Content',
		action: { type: 'toggle', label: 'Show track artwork' },
		expectContent: '"showArtwork":true',
	},
	{
		name: 'music-wave/catalog-filters',
		panel: 'Layout and labels',
		action: {
			type: 'select',
			label: 'Filters layout',
			option: 'Stacked full-width rows',
		},
		expectContent: '"layout":"stacked"',
		frontendSelector: '.mw-catalog-filters--stacked',
	},
	{
		name: 'music-wave/catalog-results',
		action: { type: 'toggle', label: 'Show result count' },
		expectContent: '"showCount":false',
		frontendSelector: '.wp-block-music-wave-catalog-results',
	},
	{
		name: 'music-wave/preview-player',
		panel: 'Appearance',
		action: { type: 'toggle', label: 'Show play icon' },
		expectContent: '"showIcon":false',
	},
	{
		name: 'music-wave/download-button',
		panel: 'MusicWave block settings',
		action: { type: 'toggle', label: 'Compact mode' },
		expectContent: '"compact":true',
	},
	{
		name: 'music-wave/related-releases',
		panel: 'Card content',
		action: { type: 'toggle', label: 'Show artwork' },
		expectContent: '"showArtwork":false',
	},
	{
		name: 'music-wave/preview-button',
		panel: 'Appearance',
		action: { type: 'toggle', label: 'Show play icon' },
		expectContent: '"showIcon":false',
	},
	{
		name: 'music-wave/artist-profile',
		action: { type: 'toggle', label: 'Show release count' },
		expectContent: '"showReleaseCount":true',
	},
	{
		name: 'music-wave/account-dashboard',
		action: { type: 'toggle', label: 'Show account statistics' },
		expectContent: '"showStats":false',
		frontendSelector: '.wp-block-music-wave-account-dashboard',
	},
	{
		name: 'music-wave/music-library',
		panel: 'Header',
		action: { type: 'toggle', label: 'Show filters' },
		expectContent: '"showFilters":false',
		frontendSelector: '.mw-music-library',
	},
	{
		name: 'music-wave/library-button',
		panel: 'Button',
		action: {
			type: 'text',
			label: 'Button label',
			value: 'Save this release',
		},
		expectContent: '"label":"Save this release"',
	},
];

async function ensureSettingsSidebar( page ) {
	const sidebar = page.locator( '.edit-post-sidebar' );
	const visible = await sidebar.isVisible().catch( () => false );
	if ( ! visible ) {
		await page.getByRole( 'button', { name: 'Settings' } ).click();
		await sidebar.waitFor();
	}
	return sidebar;
}

async function openPanel( sidebar, title ) {
	const button = sidebar
		.getByRole( 'button', { name: title, exact: true } )
		.first();
	if ( ( await button.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
		await button.click();
	}
}

async function applyAction( page, panel, action ) {
	const sidebar = await ensureSettingsSidebar( page );
	if ( panel ) {
		await openPanel( sidebar, panel );
	}

	if ( 'toggle' === action.type ) {
		await sidebar
			.getByRole( 'checkbox', { name: action.label, exact: true } )
			.click();
		return;
	}

	if ( 'select' === action.type ) {
		await sidebar
			.getByLabel( action.label, { exact: true } )
			.selectOption( { label: action.option } );
		return;
	}

	if ( 'text' === action.type ) {
		await sidebar
			.getByLabel( action.label, { exact: true } )
			.fill( action.value );
	}
}

test.describe( 'MusicWave editor blocks', () => {
	for ( const scenario of SCENARIOS ) {
		test( `${ scenario.name }: insert, change one setting, save, render`, async ( {
			admin,
			editor,
			page,
		} ) => {
			await admin.createNewPost( {
				postType: 'page',
				title: 'E2E ' + scenario.name,
			} );
			await editor.insertBlock( { name: scenario.name } );

			const blocks = await editor.getBlocks();
			expect( blocks.map( ( block ) => block.name ) ).toContain(
				scenario.name
			);

			await applyAction( page, scenario.panel, scenario.action );

			const content = await editor.getEditedPostContent();
			expect( content ).toContain( 'wp:' + scenario.name );
			expect( content ).toContain( scenario.expectContent );

			const permalink = await editor.publishPost();
			await page.goto( permalink );

			if ( scenario.frontendSelector ) {
				await expect(
					page.locator( scenario.frontendSelector ).first()
				).toBeVisible();
			} else {
				// Blocks that require a release context render nothing on a plain
				// page; the published page must still load without PHP errors.
				await expect( page.locator( 'body' ) ).not.toContainText(
					'Fatal error'
				);
			}
		} );
	}
} );
