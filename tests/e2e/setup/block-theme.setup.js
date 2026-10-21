// @ts-check
const { test } = require( '@playwright/test' );
const { resetStore } = require( '../support/wp' );
const { loginAsAdmin } = require( '../support/auth' );

test( 'store on Twenty Twenty-Five (block theme)', async ( { page } ) => {
	test.setTimeout( 120_000 );
	resetStore( 'twentytwentyfive' );
	await loginAsAdmin( page );
} );
