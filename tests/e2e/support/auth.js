// @ts-check
const { expect } = require( '@playwright/test' );

/**
 * Logs in as the wp-env admin and saves the session for the test projects.
 *
 * @param {import('@playwright/test').Page} page
 */
async function loginAsAdmin( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await expect( page ).toHaveURL( /wp-admin/ );
	await page.context().storageState( { path: 'test-results/.auth/admin.json' } );
}

module.exports = { loginAsAdmin };
