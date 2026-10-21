// @ts-check
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { addToCart, wp, setOption } = require( './support/wp' );

test.use( { storageState: { cookies: [], origins: [] } } );

/** The product's own price on its page, in Storefront and in a block theme. */
const mainPrice = ( page ) => page.locator( '.summary > .price, .wp-block-woocommerce-product-price[data-is-descendent-of-single-product-template="true"]' ).first();

test.describe( 'Product page widgets @frontend', () => {
	test( 'a reduced product crosses out its reference price, not the regular price', async ( { page } ) => {
		await page.goto( '/product/produs-promo/' );

		await expect( mainPrice( page ).locator( 'del' ) ).toContainText( '100' );
		await expect( mainPrice( page ) ).not.toContainText( '150' );
		await expect( page.locator( '.romcommerce-omnibus-floor' ) ).toContainText( '100' );
	} );

	test( 'a reduced product with no earlier history shows no crossed-out price', async ( { page } ) => {
		await page.goto( '/product/produs-redus/' );

		await expect( mainPrice( page ) ).toContainText( '80' );
		await expect( mainPrice( page ).locator( 'del' ) ).toHaveCount( 0 );
	} );

	test( 'shop listings cross out the same reference price', async ( { page } ) => {
		await page.goto( '/shop/' );

		const card = page.locator( 'li', { hasText: 'Produs promo' } ).last();
		await expect( card.locator( 'del' ) ).toContainText( '100' );
		await expect( card ).not.toContainText( '150' );
	} );

	test( 'the recommended retail price is a plain labelled line', async ( { page } ) => {
		await page.goto( '/product/produs-promo/' );

		const line = page.locator( '.romcommerce-prp' );
		await expect( line ).toContainText( '180' );
		await expect( line.locator( 'abbr' ) ).toHaveAttribute( 'title', /.+/ );
		await expect( line.locator( 'del' ) ).toHaveCount( 0 );

		await page.goto( '/product/produs-test/' );
		await expect( page.locator( '.romcommerce-prp' ) ).toContainText( '130' );
	} );

	test( 'a reduced product shows the 30-day lowest price line', async ( { page } ) => {
		await page.goto( '/product/produs-redus/' );

		const line = page.locator( '.romcommerce-omnibus-floor' );
		await expect( line ).toContainText( 'Cel mai mic preț din cele 30 de zile dinaintea acestei reduceri:' );
		// The fixture product was created already on sale, so nothing is on
		// record before the 80: the line shows the 80, never the regular 100.
		await expect( line ).toContainText( '80' );
		await expect( line ).not.toContainText( '100' );
	} );

	test( 'a product at full price shows no lowest price line', async ( { page } ) => {
		await page.goto( '/product/produs-test/' );

		await expect( page.locator( '.romcommerce-omnibus-floor' ) ).toHaveCount( 0 );
	} );

	test( 'the free shipping bar shows how much is left', async ( { page } ) => {
		await addToCart( page, 'produs-test' );
		await page.goto( '/cart/' );

		await expect( page.locator( '.romcommerce-fsb-message' ) ).toContainText( 'pentru livrare gratuită' );
	} );

	test( 'the contact button opens, links to WhatsApp with context, and closes on Escape', async ( { page } ) => {
		await page.goto( '/product/produs-test/' );

		const toggle = page.locator( '.romcommerce-fab-toggle' );
		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );

		await toggle.click();
		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );

		const whatsapp = page.locator( '.romcommerce-fab-channel[href^="https://wa.me/40712345678"]' );
		await expect( whatsapp ).toBeVisible();
		expect( decodeURIComponent( ( await whatsapp.getAttribute( 'href' ) ) || '' ) ).toContain( 'Produs test' );

		const messenger = page.locator( '.romcommerce-fab-channel[href="https://m.me/magazintest"]' );
		await expect( messenger.locator( 'svg path' ) ).toHaveCount( 1 );
		await expect( page.locator( '.romcommerce-fab-monogram' ) ).toHaveCount( 0 );

		await page.keyboard.press( 'Escape' );
		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
	} );

	test( 'a WhatsApp button under Add to Cart asks about this product', async ( { page } ) => {
		await page.goto( '/product/produs-test/' );

		const button = page.locator( 'form.cart .romcommerce-wa-atc-button' );
		await expect( button ).toBeVisible();
		await expect( button ).toHaveAttribute( 'href', /^https:\/\/wa\.me\/40712345678\?text=/ );
		expect( decodeURIComponent( ( await button.getAttribute( 'href' ) ) || '' ) ).toContain( 'Produs test - ' );

		const below = await page.evaluate( () => {
			const atc = document.querySelector( 'form.cart .single_add_to_cart_button' );
			const wa = document.querySelector( '.romcommerce-wa-atc-button' );
			return atc && wa ? wa.getBoundingClientRect().top >= atc.getBoundingClientRect().bottom : false;
		} );
		expect( below ).toBe( true );
	} );

	test( 'the WhatsApp links follow the chosen variation and reset with it', async ( { page } ) => {
		await page.goto( '/product/produs-variabil/' );

		const button = page.locator( '.romcommerce-wa-atc-button' );
		const href = async () => decodeURIComponent( ( await button.getAttribute( 'href' ) ) || '' );
		expect( await href() ).not.toContain( '(#' );

		await page.locator( 'select[name="attribute_marime"]' ).selectOption( 'M' );
		await expect.poll( href ).toMatch( /Produs variabil - .+ \(#\d+\)$/ );

		await page.locator( '.reset_variations' ).click();
		await expect.poll( href ).not.toContain( '(#' );
	} );

	test( 'RomCommerce storefront elements have no accessibility violations', async ( { page } ) => {
		await page.goto( '/product/produs-promo/' );
		await page.locator( '.romcommerce-fab-toggle' ).click();

		const results = await new AxeBuilder( { page } )
			.include( '.romcommerce-fab' )
			.include( '.romcommerce-omnibus-floor' )
			.include( '.romcommerce-prp' )
			.include( '.romcommerce-wa-atc' )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] )
			.analyze();

		expect( results.violations.map( ( v ) => `${ v.id }: ${ v.help } (${ v.nodes.length })` ) ).toEqual( [] );
	} );
} );

test.describe( 'Google Merchant Center widget @frontend', () => {
	const setEnabled = ( on ) => wp( [ 'eval', `RomCommerce\\Settings::set_enabled( 'merchant-widget', ${ on ? 'true' : 'false' } );` ] );

	test.afterEach( () => setEnabled( false ) );

	// Google's real script is swapped for a stub that records the start()
	// call: the test checks what the shop asks Google for, offline.
	test( 'starts Google\'s widget once, with the merchant\'s settings', async ( { page } ) => {
		setOption( 'romcommerce_merchant_widget', { position: 'LEFT_BOTTOM', bottomMargin: 90, sideMargin: 36, region: 'RO' } );
		setEnabled( true );

		await page.route( 'https://www.gstatic.com/shopping/merchant/merchantwidget.js', ( route ) =>
			route.fulfill( {
				contentType: 'application/javascript',
				body: 'window.__rcStarts=[];window.merchantwidget={start:function(c){window.__rcStarts.push(c);}};',
			} )
		);

		await page.goto( '/product/produs-test/' );

		await expect.poll( () => page.evaluate( () => window.__rcStarts ) ).toEqual( [
			{ position: 'LEFT_BOTTOM', bottomMargin: 90, region: 'RO' },
		] );
	} );

	test( 'loads nothing from Google while the module is off', async ( { page } ) => {
		let requested = false;
		await page.route( 'https://www.gstatic.com/shopping/merchant/**', ( route ) => {
			requested = true;
			return route.abort();
		} );

		await page.goto( '/' );

		expect( requested ).toBe( false );
	} );
} );

test.describe( 'Perks shop @frontend', () => {
	const setEnabled = ( on ) => wp( [ 'eval', `RomCommerce\\Settings::set_enabled( 'perks-shop', ${ on ? 'true' : 'false' } );` ] );
	test.beforeAll( () => setEnabled( true ) );
	test.afterAll( () => setEnabled( false ) );

	test( 'the default badges show on the product page, after Add to Cart', async ( { page } ) => {
		await page.goto( wp( [ 'eval', 'echo get_permalink( get_page_by_path( "produs-test", OBJECT, "product" ) );' ] ) );

		const perks = page.locator( '.romcommerce-perks' ).first();
		await expect( perks.locator( '.romcommerce-perk-title' ) ).toHaveText( [ 'Livrare rapidă', 'Retur în 14 zile', 'Plată securizată' ] );

		const button = await page.locator( 'button[name="add-to-cart"], .single_add_to_cart_button' ).first().boundingBox();
		const badges = await perks.boundingBox();
		expect( badges?.y ).toBeGreaterThan( button?.y ?? 0 );
	} );
} );

test.describe( 'Reviews showcase @frontend', () => {
	let reviewId = 0;
	test.beforeAll( () => {
		wp( [ 'eval', "RomCommerce\\Settings::set_enabled( 'reviews-showcase', true );" ] );
		reviewId = Number(
			wp( [
				'eval',
				"$id = wp_insert_comment( array( 'comment_post_ID' => get_page_by_path( 'produs-test', OBJECT, 'product' )->ID, 'comment_author' => 'Maria Ionescu', 'comment_content' => 'Produs foarte bun, livrare rapidă.', 'comment_type' => 'review', 'comment_approved' => 1 ) ); update_comment_meta( $id, 'rating', 5 ); echo $id;",
			] )
		);
	} );
	test.afterAll( () => {
		wp( [ 'eval', `wp_delete_comment( ${ reviewId }, true ); RomCommerce\\Settings::set_enabled( 'reviews-showcase', false );` ] );
	} );

	test( 'the shop page shows recent reviews below the products', async ( { page } ) => {
		await page.goto( wp( [ 'eval', 'echo get_permalink( wc_get_page_id( "shop" ) );' ] ) );

		const showcase = page.locator( '.romcommerce-reviews' );
		await expect( showcase ).toContainText( 'Produs foarte bun, livrare rapidă.' );
		await expect( showcase ).toContainText( 'Maria I.' );
		// WooCommerce's own star markup; its label is WooCommerce's string, English without its language pack.
		await expect( showcase.locator( '.star-rating' ) ).toHaveAttribute( 'aria-label', /5/ );

		const lastProduct = await page.locator( 'li.product, .wc-block-product' ).last().boundingBox();
		const box = await showcase.boundingBox();
		expect( box?.y ).toBeGreaterThan( lastProduct?.y ?? 0 );
	} );
} );

test.describe( 'Show some love @frontend', () => {
	const setEnabled = ( on ) => wp( [ 'eval', `RomCommerce\\Settings::set_enabled( 'footer-credit', ${ on ? 'true' : 'false' } );` ] );
	test.afterAll( () => setEnabled( false ) );

	test( 'no credit until the merchant switches it on, then a nofollow link inside the footer', async ( { page } ) => {
		setEnabled( false );
		await page.goto( '/' );
		await expect( page.locator( '#romcommerce-footer-credit' ) ).toHaveCount( 0 );

		setEnabled( true );
		await page.goto( '/' );
		const link = page.locator( 'footer #romcommerce-footer-credit a' );
		await expect( link ).toHaveAttribute( 'href', 'https://romcommerce.ro/' );
		await expect( link ).toHaveAttribute( 'rel', 'nofollow' );
	} );
} );

test.describe( 'Frequently bought together @frontend', () => {
	const pick = ( product, picks ) => wp( [
		'eval',
		`$ids = array_map( function ( $slug ) { return get_page_by_path( $slug, OBJECT, 'product' )->ID; }, ${ JSON.stringify( picks ).replace( /"/g, "'" ).replace( /^\[/, 'array(' ).replace( /\]$/, ')' ) } ); update_post_meta( get_page_by_path( '${ product }', OBJECT, 'product' )->ID, '_romcommerce_fbt_ids', $ids );`,
	] );
	test.beforeAll( () => {
		wp( [ 'eval', "RomCommerce\\Settings::set_enabled( 'frequently-bought-together', true );" ] );
		pick( 'produs-test', [ 'produs-redus', 'produs-promo', 'produs-epuizat' ] );
		pick( 'produs-variabil', [ 'produs-redus' ] );
	} );
	test.afterAll( () => {
		wp( [ 'eval', "delete_post_meta_by_key( '_romcommerce_fbt_ids' ); RomCommerce\\Settings::set_enabled( 'frequently-bought-together', false );" ] );
	} );

	test( 'the bundle lists what can be bought, keeps a live total and adds everything ticked to the cart', async ( { page } ) => {
		await page.goto( '/product/produs-test/' );

		const bundle = page.locator( '.romcommerce-fbt' );
		await expect( bundle.locator( '.romcommerce-fbt-name' ) ).toHaveText( [ 'Produs test', 'Produs redus', 'Produs promo' ] );
		const total = bundle.locator( '[data-rc-fbt-total]' );
		await expect( total ).toContainText( '260' );

		await bundle.getByRole( 'checkbox', { name: 'Produs promo' } ).uncheck();
		await expect( total ).toContainText( '180' );

		await bundle.locator( '.romcommerce-fbt-button' ).click();
		await page.goto( '/cart/' );
		const cart = page.locator( '.woocommerce-cart-form, .wp-block-woocommerce-cart' );
		await expect( cart ).toContainText( 'Produs test' );
		await expect( cart ).toContainText( 'Produs redus' );
		await expect( cart ).not.toContainText( 'Produs promo' );
	} );

	test( 'with nothing ticked the button is disabled', async ( { page } ) => {
		await page.goto( '/product/produs-test/' );

		const bundle = page.locator( '.romcommerce-fbt' );
		for ( const box of await bundle.getByRole( 'checkbox' ).all() ) {
			await box.uncheck();
		}
		await expect( bundle.locator( '.romcommerce-fbt-button' ) ).toBeDisabled();
	} );

	test( 'a variable product joins the bundle once a variation is picked', async ( { page } ) => {
		await page.goto( '/product/produs-variabil/' );

		const current = page.locator( '[data-rc-fbt-current] input' );
		await expect( current ).toBeDisabled();

		await page.locator( 'select[name="attribute_marime"]' ).selectOption( 'M' );
		await expect( current ).toBeEnabled();
		await expect( current ).toBeChecked();
		await expect( current ).not.toHaveValue( '' );
	} );

	test( 'the bundle has no accessibility violations', async ( { page } ) => {
		await page.goto( '/product/produs-test/' );

		const results = await new AxeBuilder( { page } )
			.include( '.romcommerce-fbt' )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] )
			.analyze();

		expect( results.violations.map( ( v ) => `${ v.id }: ${ v.help } (${ v.nodes.length })` ) ).toEqual( [] );
	} );
} );
