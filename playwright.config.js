// Playwright Test configuration for the end-to-end suite.
//
// `webServer` boots a disposable WordPress with wp-playground-cli (see
// tests/e2e/playground.js) and tears it down afterwards. Set PLAYGROUND_URL to
// run against a site that is already up instead.
const { defineConfig } = require( 'playwright/test' );

const PORT = Number( process.env.PLAYGROUND_PORT || 9400 );
const baseURL = ( process.env.PLAYGROUND_URL || 'http://127.0.0.1:' + PORT ).replace( /\/$/, '' );

module.exports = defineConfig( {
	testDir: 'tests/e2e',
	testMatch: /.*\.spec\.js/,
	// One WordPress, one worker: the tests share its database.
	workers: 1,
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	timeout: 60000,
	expect: { timeout: 15000 },
	reporter: process.env.CI ? [ [ 'list' ], [ 'github' ] ] : 'list',
	use: {
		baseURL,
		trace: 'retain-on-failure',
	},
	webServer: process.env.PLAYGROUND_URL
		? undefined
		: {
				command: 'node tests/e2e/playground.js',
				url: baseURL + '/.well-known/oauth-authorization-server',
				timeout: 300000,
				reuseExistingServer: false,
				stdout: process.env.VERBOSE ? 'pipe' : 'ignore',
				stderr: 'pipe',
		  },
} );
