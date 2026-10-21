// @ts-check
// Dev-only browser tests against the wp-env development site (port 8888).
// The PHPUnit integration suite owns the separate tests site (8889) and wipes
// its database on every run, so browser tests must never point there.
const { defineConfig, devices } = require( '@playwright/test' );

const baseURL = process.env.WP_BASE_URL || 'http://localhost:8888';

module.exports = defineConfig( {
	testDir: './tests/e2e',
	outputDir: './test-results',
	snapshotPathTemplate: '{testDir}/__screenshots__/{projectName}/{testFilePath}/{arg}{ext}',
	// Theme and settings are site-wide state, so tests can't run in parallel.
	workers: 1,
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	forbidOnly: !! process.env.CI,
	reporter: process.env.CI ? [ [ 'github' ], [ 'html', { open: 'never' } ] ] : [ [ 'list' ], [ 'html', { open: 'never' } ] ],
	expect: {
		toHaveScreenshot: { maxDiffPixelRatio: 0.01, animations: 'disabled' },
	},
	use: {
		baseURL,
		locale: 'ro-RO',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		...devices[ 'Desktop Chrome' ],
		// Set by `npm run test:e2e:baselines`, which renders screenshot baselines in
		// Playwright's Linux container: the site's URLs say localhost:8888, so the
		// browser resolves that name to the Docker host instead of the container.
		launchOptions: process.env.PW_IN_DOCKER
			? { args: [ '--host-resolver-rules=MAP localhost host.docker.internal' ] }
			: {},
	},
	projects: [
		{ name: 'setup-storefront', testMatch: /setup\/storefront\.setup\.js/ },
		{
			name: 'storefront',
			dependencies: [ 'setup-storefront' ],
			testIgnore: /(setup|checks)\//,
			grepInvert: /@blocks/,
			use: { storageState: 'test-results/.auth/admin.json' },
		},
		{ name: 'setup-block-theme', testMatch: /setup\/block-theme\.setup\.js/, dependencies: [ 'storefront' ] },
		{
			name: 'block-theme',
			dependencies: [ 'setup-block-theme' ],
			testIgnore: /(setup|checks)\//,
			grep: /@frontend/,
			grepInvert: /@blocks/,
			use: { storageState: 'test-results/.auth/admin.json' },
		},
		{ name: 'setup-block-checkout', testMatch: /setup\/block-checkout\.setup\.js/, dependencies: [ 'block-theme' ] },
		{
			name: 'block-checkout',
			dependencies: [ 'setup-block-checkout' ],
			testIgnore: /(setup|checks)\//,
			grep: /@blocks/,
			use: { storageState: 'test-results/.auth/admin.json' },
		},
		{ name: 'php-errors', testMatch: /checks\/php-errors\.check\.js/, dependencies: [ 'block-checkout' ] },
	],
} );
