// @ts-check
const { test, expect } = require( '@playwright/test' );

test.use( { storageState: { cookies: [], origins: [] } } );

test.describe( 'Branded login screen', () => {
	test( 'uses the brand colour on the login button', async ( { page } ) => {
		await page.goto( '/wp-login.php' );

		await expect( page.locator( '#wp-submit' ) ).toHaveCSS( 'background-color', 'rgb(192, 57, 43)' );
	} );

	test( 'focused login fields keep a single, unclipped border @visual', async ( { page } ) => {
		test.skip( ! process.env.PW_IN_DOCKER, 'Screenshot tests run only in the pinned Playwright container (npm run test:e2e:visual), so fonts render identically everywhere.' );

		await page.goto( '/wp-login.php' );
		await page.focus( '#user_login' );

		await expect( page.locator( '#login' ) ).toHaveScreenshot( 'login-focused.png' );
	} );
} );
