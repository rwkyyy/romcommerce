// @ts-check
const { test } = require( '@playwright/test' );
const { resetStore } = require( '../support/wp' );
const { loginAsAdmin } = require( '../support/auth' );

test( 'store on Twenty Twenty-Five with block cart and checkout', async ( { page } ) => {
	test.setTimeout( 120_000 );
	resetStore( 'twentytwentyfive', 'blocks' );
	await loginAsAdmin( page );
} );
