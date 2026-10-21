// @ts-check
const { test, expect } = require( '@playwright/test' );
const { wp, addToCart } = require( './support/wp' );

test.use( { storageState: { cookies: [], origins: [] } } );

const VALID_CUI = 'RO18547290';

/**
 * @param {import('@playwright/test').Page} page
 * @param {Record<string, string>} [overrides]
 */
async function fillCheckout( page, overrides = {} ) {
	await addToCart( page, 'produs-test' );
	await page.goto( '/checkout/' );

	const fields = {
		billing_first_name: 'Ion',
		billing_last_name: 'Popescu',
		billing_address_1: 'Strada Lipscani 10',
		billing_city: 'București',
		billing_postcode: '030031',
		billing_phone: '0712345678',
		billing_email: `ion.${ Date.now() }@example.ro`,
		...overrides,
	};
	for ( const [ id, value ] of Object.entries( fields ) ) {
		await page.fill( `#${ id }`, value );
	}
	await page.selectOption( '#billing_state', 'B', { force: true } );
}

/**
 * @param {import('@playwright/test').Page} page
 * @return {Promise<number>} the new order's id.
 */
async function placeOrder( page ) {
	await page.click( '#place_order' );
	await expect( page ).toHaveURL( /order-received\/(\d+)/, { timeout: 30_000 } );

	return Number( /order-received\/(\d+)/.exec( page.url() )?.[ 1 ] );
}

/** @param {number} orderId */
function billingMeta( orderId ) {
	return JSON.parse( wp( [ 'eval', `echo wp_json_encode( wc_get_order( ${ orderId } )->get_meta( '_romcommerce_billing' ) );` ] ) );
}

test.describe( 'Checkout @frontend', () => {
	test( 'an individual (PF) order goes through with the CNP placeholder', async ( { page } ) => {
		await fillCheckout( page );
		await page.selectOption( '#billing_romcommerce_person_type', 'pf' );
		await expect( page.locator( '#romcommerce-pj-fields' ) ).toBeHidden();

		const orderId = await placeOrder( page );

		expect( billingMeta( orderId ) ).toEqual( { type: 'pf', cnp: '0000000000000' } );
	} );

	test( 'a company (PJ) order with a valid CUI goes through', async ( { page } ) => {
		await fillCheckout( page );
		await page.selectOption( '#billing_romcommerce_person_type', 'pj' );
		await expect( page.locator( '#romcommerce-pj-fields' ) ).toBeVisible();
		await page.fill( '#billing_company', 'Firma Test SRL' );
		await page.fill( '#billing_romcommerce_cui', 'ro 185 472 90' );

		const orderId = await placeOrder( page );

		expect( billingMeta( orderId ) ).toMatchObject( { type: 'pj', cui: VALID_CUI } );
	} );

	test( 'a company order with a bad CUI is stopped with a Romanian message', async ( { page } ) => {
		await fillCheckout( page );
		await page.selectOption( '#billing_romcommerce_person_type', 'pj' );
		await page.fill( '#billing_company', 'Firma Test SRL' );
		await page.fill( '#billing_romcommerce_cui', 'RO18547291' );
		await page.click( '#place_order' );

		await expect( page.locator( '.woocommerce-error, .wc-block-components-notice-banner.is-error' ) ).toContainText( 'CUI / CIF-ul introdus nu are un format valid.' );
		await expect( page ).not.toHaveURL( /order-received/ );
	} );

	test( 'a five-digit Romanian postcode is rejected', async ( { page } ) => {
		await fillCheckout( page, { billing_postcode: '03003' } );
		await page.click( '#place_order' );

		await expect( page.locator( '.woocommerce-error, .wc-block-components-notice-banner.is-error' ) ).toContainText( 'exact 6 cifre' );
	} );
} );

test.describe( 'Phone validation @frontend', () => {
	const setEnabled = ( on ) => wp( [ 'eval', `RomCommerce\\Settings::set_enabled( 'phone-validation', ${ on ? 'true' : 'false' } );` ] );
	test.beforeAll( () => setEnabled( true ) );
	test.afterAll( () => {
		setEnabled( false );
		wp( [ 'option', 'update', 'woocommerce_allowed_countries', 'specific' ] );
	} );

	test( 'the phone field gets a country picker', async ( { page } ) => {
		await fillCheckout( page );

		await expect( page.locator( '.iti' ).filter( { has: page.locator( '#billing_phone' ) } ) ).toBeVisible();
	} );

	test( 'a Romanian mobile number goes through', async ( { page } ) => {
		await fillCheckout( page );

		const orderId = await placeOrder( page );

		expect( wp( [ 'eval', `echo wc_get_order( ${ orderId } )->get_billing_phone();` ] ) ).toMatch( /^0712\s?345\s?678$/ );
	} );

	test( 'an invalid number is stopped with a Romanian message', async ( { page } ) => {
		await fillCheckout( page, { billing_phone: '071234' } );
		await page.click( '#place_order' );

		await expect( page.locator( '.woocommerce-error, .wc-block-components-notice-banner.is-error' ) ).toContainText( 'Numărul de telefon introdus nu este valid.' );
	} );

	test( 'the picker only offers the countries the store sells to', async ( { page } ) => {
		await fillCheckout( page );
		const picker = page.locator( '.iti' ).filter( { has: page.locator( '#billing_phone' ) } );
		await picker.locator( '.iti__selected-country' ).click();

		await expect( page.getByRole( 'option', { name: /\(\+\d+\)$/ } ) ).toHaveCount( 1 );
		await expect( page.getByRole( 'option', { name: 'Romania (+40)' } ) ).toBeVisible();
	} );

	test( 'a number picked for another country is sent in international form', async ( { page } ) => {
		wp( [ 'option', 'update', 'woocommerce_allowed_countries', 'all' ] );
		await fillCheckout( page );
		const picker = page.locator( '.iti' ).filter( { has: page.locator( '#billing_phone' ) } );
		await picker.locator( '.iti__selected-country' ).click();
		await page.locator( '.iti__search-input:visible' ).fill( 'Germany' );
		await page.getByRole( 'option', { name: 'Germany (+49)' } ).click();
		await page.fill( '#billing_phone', '030 1234567' );

		const orderId = await placeOrder( page );

		wp( [ 'option', 'update', 'woocommerce_allowed_countries', 'specific' ] );
		expect( wp( [ 'eval', `echo wc_get_order( ${ orderId } )->get_billing_phone();` ] ) ).toBe( '+49301234567' );
	} );
} );
