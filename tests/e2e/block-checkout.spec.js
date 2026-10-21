// @ts-check
const { test, expect } = require( '@playwright/test' );
const { wp, setOption, addToCart } = require( './support/wp' );

test.use( { storageState: { cookies: [], origins: [] } } );

/**
 * @param {import('@playwright/test').Page} page
 * @param {Record<string, string>} [overrides] field id => value.
 */
async function fillBlockCheckout( page, overrides = {} ) {
	await addToCart( page, 'produs-test' );
	await page.goto( wp( [ 'eval', 'echo wc_get_checkout_url();' ] ) );

	const fields = {
		email: `ion.${ Date.now() }@example.ro`,
		'shipping-first_name': 'Ion',
		'shipping-last_name': 'Popescu',
		'shipping-address_1': 'Strada Lipscani 10',
		'shipping-city': 'București',
		'shipping-postcode': '030031',
		'shipping-phone': '0712345678',
		...overrides,
	};
	for ( const [ id, value ] of Object.entries( fields ) ) {
		await page.fill( `#${ id }`, value );
	}
	await page.selectOption( '#shipping-state', 'B' );
}

/**
 * @param {import('@playwright/test').Page} page
 * @return {Promise<number>} the new order's id.
 */
async function placeBlockOrder( page ) {
	await page.click( '.wc-block-components-checkout-place-order-button' );
	await expect( page ).toHaveURL( /order-received\/(\d+)/, { timeout: 30_000 } );

	return Number( /order-received\/(\d+)/.exec( page.url() )?.[ 1 ] );
}

/** @param {number} orderId */
function billingMeta( orderId ) {
	return JSON.parse( wp( [ 'eval', `echo wp_json_encode( wc_get_order( ${ orderId } )->get_meta( '_romcommerce_billing' ) );` ] ) );
}

/** @param {import('@playwright/test').Page} page */
async function fillCompany( page, cui ) {
	await page.getByLabel( 'Cumpăr ca persoană juridică (firmă)' ).check();
	await page.getByLabel( 'Denumire firmă' ).fill( 'Exemplu SRL' );
	await page.getByLabel( 'CUI / CIF' ).fill( cui );
}

test.describe( 'Block cart and checkout @blocks', () => {
	test( 'the block cart renders with RomCommerce active', async ( { page } ) => {
		await addToCart( page, 'produs-test' );
		await page.goto( wp( [ 'eval', 'echo wc_get_cart_url();' ] ) );

		await expect( page.locator( '.wp-block-woocommerce-cart' ) ).toBeVisible();
		await expect( page.locator( '.wc-block-cart-items' ) ).toContainText( 'Produs test' );
		// Control for the Hide voucher test below: the field is there by default.
		await expect( page.locator( '.wc-block-components-totals-coupon' ) ).toHaveCount( 1 );
	} );

	test( 'an individual (PF) order stores the CNP placeholder', async ( { page } ) => {
		await fillBlockCheckout( page );
		await expect( page.getByLabel( 'CUI / CIF' ) ).toBeHidden();

		const orderId = await placeBlockOrder( page );

		expect( billingMeta( orderId ) ).toEqual( { type: 'pf', cnp: '0000000000000' } );
	} );

	test( 'the company box sits before the addresses', async ( { page } ) => {
		await addToCart( page, 'produs-test' );
		await page.goto( wp( [ 'eval', 'echo wc_get_checkout_url();' ] ) );

		const box = await page.getByLabel( 'Cumpăr ca persoană juridică (firmă)' ).boundingBox();
		const address = await page.locator( '#shipping-first_name' ).boundingBox();

		expect( box?.y ).toBeLessThan( address?.y ?? 0 );
	} );

	test( 'a company (PJ) order with a valid CUI stores the company data', async ( { page } ) => {
		await fillBlockCheckout( page );
		await fillCompany( page, 'RO18547290' );

		const orderId = await placeBlockOrder( page );

		expect( billingMeta( orderId ) ).toEqual( { type: 'pj', cui: 'RO18547290', reg_com: '', bank: '', iban: '' } );
		expect( wp( [ 'eval', `echo wc_get_order( ${ orderId } )->get_billing_company();` ] ) ).toBe( 'Exemplu SRL' );
	} );

	test( 'a company order with a bad CUI is stopped with a Romanian message', async ( { page } ) => {
		await fillBlockCheckout( page );
		await fillCompany( page, 'RO18547291' );

		await page.click( '.wc-block-components-checkout-place-order-button' );

		await expect( page.getByText( 'CUI / CIF-ul introdus nu are un format valid.' ).first() ).toBeVisible( { timeout: 15_000 } );
		await expect( page ).not.toHaveURL( /order-received/ );
	} );

	test( 'a company order without a CUI is stopped', async ( { page } ) => {
		await fillBlockCheckout( page );
		await page.getByLabel( 'Cumpăr ca persoană juridică (firmă)' ).check();
		await page.getByLabel( 'Denumire firmă' ).fill( 'Exemplu SRL' );

		await page.click( '.wc-block-components-checkout-place-order-button' );

		await expect( page ).not.toHaveURL( /order-received/, { timeout: 5_000 } );
	} );

	// WooCommerce's own client-side postcode check stops this before the
	// Store API is called; RomCommerce's server-side check covers direct
	// Store API requests (unit-tested).
	test( 'a five-digit Romanian postcode is rejected', async ( { page } ) => {
		await fillBlockCheckout( page, { 'shipping-postcode': '03003' } );

		await page.click( '.wc-block-components-checkout-place-order-button' );

		await expect( page.locator( '#shipping-postcode' ) ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( page ).not.toHaveURL( /order-received/ );
	} );
} );

test.describe( 'Block cart widgets @blocks', () => {
	test( 'the free shipping bar follows quantity changes without a reload', async ( { page } ) => {
		await addToCart( page, 'produs-test' );
		await page.goto( wp( [ 'eval', 'echo wc_get_cart_url();' ] ) );

		const message = page.locator( '.romcommerce-fsb-message' ).first();
		await expect( message ).toContainText( '200' );

		await page.locator( '.wc-block-components-quantity-selector__button--plus' ).first().click();

		await expect( message ).toContainText( '100' );
	} );

	test( 'clear cart empties the block cart', async ( { page } ) => {
		page.on( 'dialog', ( dialog ) => dialog.accept() );
		await addToCart( page, 'produs-test' );
		await page.goto( wp( [ 'eval', 'echo wc_get_cart_url();' ] ) );

		await page.locator( '.romcommerce-clear-cart' ).click();

		await expect( page.locator( '.wp-block-woocommerce-empty-cart-block' ) ).toBeVisible();
	} );

	test.describe( 'with Hide voucher on for the cart and checkout', () => {
		const setHideVoucher = ( on ) => {
			wp( [ 'eval', `RomCommerce\\Settings::set_enabled( 'hide-voucher', ${ on ? 'true' : 'false' } );` ] );
			setOption( 'romcommerce_hide_voucher', { hide_checkout: true, hide_cart: true } );
		};
		test.beforeAll( () => setHideVoucher( true ) );
		test.afterAll( () => setHideVoucher( false ) );

		test( 'neither the block cart nor the block checkout offers a coupon field', async ( { page } ) => {
			await addToCart( page, 'produs-test' );
			await page.goto( wp( [ 'eval', 'echo wc_get_cart_url();' ] ) );
			await expect( page.locator( '.wc-block-components-totals-item' ).first() ).toBeVisible();
			await expect( page.locator( '.wc-block-components-totals-coupon' ) ).toHaveCount( 0 );

			await page.goto( wp( [ 'eval', 'echo wc_get_checkout_url();' ] ) );
			await expect( page.locator( '#email' ) ).toBeVisible();
			await expect( page.locator( '.wc-block-components-totals-coupon' ) ).toHaveCount( 0 );
		} );
	} );
} );

test.describe( 'Saved delivery addresses on the block checkout @blocks', () => {
	test.use( { storageState: 'test-results/.auth/admin.json' } );

	let addressId = '';
	test.beforeAll( () => {
		wp( [ 'eval', "RomCommerce\\Settings::set_enabled( 'multi-address', true );" ] );
		addressId = wp( [
			'eval',
			`echo RomCommerce\\Modules\\MultiAddress\\AddressBook::save( 1, array( 'label' => 'Birou', 'first_name' => 'Ana', 'last_name' => 'Ionescu', 'country' => 'RO', 'state' => 'CJ', 'city' => 'Cluj-Napoca', 'postcode' => '400001', 'address_1' => 'Strada Memorandumului 1', 'phone' => '0722000000' ) );`,
		] );
	} );
	test.afterAll( () => {
		wp( [ 'eval', `RomCommerce\\Modules\\MultiAddress\\AddressBook::delete( 1, '${ addressId }' );` ] );
		wp( [ 'eval', "RomCommerce\\Settings::set_enabled( 'multi-address', false );" ] );
	} );

	test( 'choosing a saved address fills the block shipping address', async ( { page } ) => {
		await addToCart( page, 'produs-test' );
		await page.goto( wp( [ 'eval', 'echo wc_get_checkout_url();' ] ) );

		await page.locator( '#romcommerce-saved-address-block' ).selectOption( { label: 'Birou' } );

		await expect.poll( () => page.evaluate( () => window.wp.data.select( 'wc/store/cart' ).getCustomerData().shippingAddress.city ) ).toBe( 'Cluj-Napoca' );
		await expect.poll( () => page.evaluate( () => window.wp.data.select( 'wc/store/cart' ).getCustomerData().shippingAddress.state ) ).toBe( 'CJ' );
	} );
} );

test.describe( 'Placements map with block cart and checkout @blocks', () => {
	test.use( { storageState: 'test-results/.auth/admin.json' } );

	test( 'draws the block layout with the modules placed inside it', async ( { page } ) => {
		for ( const surface of [ 'cart', 'checkout' ] ) {
			await page.goto( `/wp-admin/admin.php?page=romcommerce&tab=placements&surface=${ surface }` );

			await expect( page.locator( '.rc-pm-layout' ) ).toHaveClass( /is-block/ );
			await expect( page.locator( '.rc-pm-scene .rc-pm-slot.is-occupied' ).first() ).toBeVisible();
			await expect( page.locator( '.rc-pm-side' ) ).not.toContainText( 'Classic only' );
		}
	} );
} );

test.describe( 'Related products on a block theme @blocks', () => {
	const related = '.wp-block-woocommerce-product-collection[data-collection="woocommerce/product-collection/related"]';
	const productUrl = () => wp( [ 'eval', 'echo get_permalink( get_page_by_path( "produs-test", OBJECT, "product" ) );' ] );
	const configure = ( settings ) => {
		wp( [ 'eval', "RomCommerce\\Settings::set_enabled( 'related-products', true );" ] );
		setOption( 'romcommerce_related_products', settings );
	};
	test.afterAll( () => wp( [ 'eval', "RomCommerce\\Settings::set_enabled( 'related-products', false );" ] ) );

	test( 'the related collection follows the module count and columns', async ( { page } ) => {
		configure( { relate_category: true, relate_tag: true, count: 1, columns: 2 } );
		await page.goto( productUrl() );

		await expect( page.locator( `${ related } .wc-block-product` ) ).toHaveCount( 1 );
		await expect( page.locator( related ) ).toHaveAttribute( 'data-display-layout', /"columns":2/ );
	} );

	test( 'hiding related products removes the block', async ( { page } ) => {
		configure( { hide: true } );
		await page.goto( productUrl() );

		await expect( page.locator( '.wp-block-woocommerce-product-template' ).first() ).toHaveCount( 0 );
		await expect( page.locator( related ) ).toHaveCount( 0 );
	} );
} );

test.describe( 'Availability sorting on a block theme @blocks', () => {
	test.afterAll( () => wp( [ 'eval', "RomCommerce\\Settings::set_enabled( 'availability-sorting', false );" ] ) );

	test( 'the shop page moves out-of-stock products to the end', async ( { page } ) => {
		const shop = wp( [ 'eval', 'echo get_permalink( wc_get_page_id( "shop" ) );' ] );
		const names = page.locator( '.wc-block-product .wp-block-post-title' );

		await page.goto( shop );
		await expect( names.first() ).toHaveText( 'Aaa produs epuizat' );

		wp( [ 'eval', "RomCommerce\\Settings::set_enabled( 'availability-sorting', true );" ] );
		await page.goto( shop );
		await expect( names.last() ).toHaveText( 'Aaa produs epuizat' );
	} );
} );

test.describe( 'Phone validation on the block checkout @blocks', () => {
	const setEnabled = ( on ) => wp( [ 'eval', `RomCommerce\\Settings::set_enabled( 'phone-validation', ${ on ? 'true' : 'false' } );` ] );
	test.beforeAll( () => setEnabled( true ) );
	test.afterAll( () => setEnabled( false ) );

	test( 'an invalid number shows an error under the field and blocks the order', async ( { page } ) => {
		await fillBlockCheckout( page, { 'shipping-phone': '071234' } );
		await page.locator( '#shipping-phone' ).blur();

		await expect( page.getByText( 'Numărul de telefon introdus nu este valid.' ).first() ).toBeVisible();

		await page.click( '.wc-block-components-checkout-place-order-button' );
		await expect( page ).not.toHaveURL( /order-received/, { timeout: 5_000 } );
	} );

	test( 'a valid number goes through', async ( { page } ) => {
		await fillBlockCheckout( page );
		await page.locator( '#shipping-phone' ).blur();

		await placeBlockOrder( page );
	} );
} );
