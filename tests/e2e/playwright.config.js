/**
 * Playwright configuration for the MusicWave editor E2E suite.
 *
 * The suite runs against the disposable local staging install described in
 * docs/staging.md. Start that install first, then:
 *
 *   npm run test:e2e
 *
 * Connection details can be overridden through environment variables:
 * WP_BASE_URL, WP_USERNAME, WP_PASSWORD.
 */

process.env.WP_USERNAME = process.env.WP_USERNAME || 'musicwave_admin';
process.env.WP_PASSWORD = process.env.WP_PASSWORD || 'MusicWave-Staging-2026!';

const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: '.',
	testMatch: '**/*.spec.js',
	fullyParallel: false,
	workers: 1,
	retries: 1,
	timeout: 180000,
	expect: { timeout: 15000 },
	reporter: [ [ 'list' ] ],
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://127.0.0.1:8088',
		headless: true,
		viewport: { width: 1366, height: 900 },
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
} );
