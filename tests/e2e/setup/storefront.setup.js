// @ts-check
const { test } = require( '@playwright/test' );
const { resetStore, wp } = require( '../support/wp' );
const { loginAsAdmin } = require( '../support/auth' );

test( 'store on Storefront (classic theme)', async ( { page } ) => {
	test.setTimeout( 120_000 );
	wp( [ 'eval', 'file_put_contents( WP_CONTENT_DIR . "/debug.log", "" );' ] );
	resetStore( 'storefront' );
	await loginAsAdmin( page );
} );
