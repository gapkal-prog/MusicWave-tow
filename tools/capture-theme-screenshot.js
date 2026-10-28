/**
 * Capture theme screenshot.png at 1200x900 using Playwright.
 * Requires wp-env running with demo content imported.
 * Usage: npm run screenshot:theme or node tools/capture-theme-screenshot.js
 */
const { chromium } = require( 'playwright' );
const path = require( 'path' );
const fs = require( 'fs' );

( async () => {
	const siteUrl = process.env.WP_ENV_URL || 'http://localhost:8888';
	const output = path.resolve( __dirname, '../musicwave/screenshot.png' );

	console.log( `Starting browser, opening ${ siteUrl } ...` );
	const browser = await chromium.launch();
	const context = await browser.newContext( {
		viewport: { width: 1200, height: 900 },
		deviceScaleFactor: 1,
	} );
	const page = await context.newPage();

	// Hide admin bar, debug bars
	await page.goto( siteUrl, { waitUntil: 'networkidle' } );
	await page.waitForTimeout( 2000 );

	// Ensure fonts and images loaded
	await page.evaluate( () => {
		const style = document.createElement( 'style' );
		style.textContent =
			'#wpadminbar, .query-monitor, .debug-bar { display: none !important; }';
		document.head.appendChild( style );
	} );

	await page.waitForTimeout( 1000 );

	await page.screenshot( { path: output, fullPage: false } );
	console.log( `Saved to ${ output }` );

	await browser.close();

	// Optimize PNG size check
	const stats = fs.statSync( output );
	console.log( `File size: ${ ( stats.size / 1024 ).toFixed( 1 ) } KB` );
	if ( stats.size > 400 * 1024 ) {
		console.warn(
			'Warning: screenshot.png >400KB, consider optimizing with pngquant or similar.'
		);
	}
} )();
