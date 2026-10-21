// @ts-check
const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../support/wp' );

// Runs after every browser test: any PHP warning, notice or deprecation that
// RomCommerce wrote to debug.log during the run fails it, even when no
// assertion looked at the page where it happened.
test( 'RomCommerce logged no PHP errors during the browser run', () => {
	const log = wp( [ 'eval', 'echo @file_get_contents( WP_CONTENT_DIR . "/debug.log" );' ] );
	const ours = log.split( '\n' ).filter( ( line ) => line.includes( '/plugins/romcommerce/' ) );

	expect( ours ).toEqual( [] );
} );
