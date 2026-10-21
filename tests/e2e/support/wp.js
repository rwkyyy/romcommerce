// @ts-check
const { execFileSync } = require( 'node:child_process' );

/**
 * Runs a WP-CLI command in the wp-env development site and returns stdout.
 *
 * @param {string[]} args
 * @return {string}
 */
function wp( args ) {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'cli', '--', 'wp', ...args ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).trim();
}

/**
 * Rebuilds the known store state (tests/e2e/fixtures/store.php) on a theme.
 *
 * @param {string} theme
 * @param {'classic'|'blocks'} [checkout] Shortcode or block cart/checkout pages.
 */
function resetStore( theme, checkout = 'classic' ) {
	wp( [ 'eval-file', 'wp-content/romcommerce-tests/e2e/fixtures/store.php', theme, checkout ] );
}

/**
 * Sets one RomCommerce module's settings option for the next page load.
 *
 * @param {string} option Full option name, e.g. romcommerce_whatsapp_fab.
 * @param {object} value
 */
function setOption( option, value ) {
	wp( [ 'option', 'update', option, JSON.stringify( value ), '--format=json' ] );
}

/** @type {Record<string, number>} */
const productIds = {};

/**
 * Adds a fixture product to the cart through WooCommerce's ?add-to-cart= URL,
 * which behaves the same on classic and block themes (block themes add to
 * cart over AJAX without the classic "added to cart" notice).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} slug
 */
async function addToCart( page, slug ) {
	if ( ! productIds[ slug ] ) {
		productIds[ slug ] = Number( wp( [ 'post', 'list', '--post_type=product', `--name=${ slug }`, '--field=ID' ] ) );
	}

	await page.goto( `/?add-to-cart=${ productIds[ slug ] }` );
}

module.exports = { wp, resetStore, setOption, addToCart };
