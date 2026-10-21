// @ts-check
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { wp, setOption, addToCart } = require( './support/wp' );

/** @return {Array<{id: string, category: string, title: string}>} every module Lite registers. */
function liteModules() {
	return JSON.parse(
		wp( [
			'eval',
			'$out = array(); foreach ( RomCommerce\\Plugin::instance()->modules()->all() as $id => $m ) { $meta = RomCommerce\\Admin\\ModuleRegistry::get( $id ); $out[] = array( "id" => $id, "category" => $meta ? $meta["category"] : "", "title" => $m->title() ); } echo wp_json_encode( $out );',
		] )
	);
}

const PHP_ERROR = /(Fatal error|Warning|Notice|Deprecated|Parse error)<\/b>:|There has been a critical error/;

test.describe( 'RomCommerce admin', () => {
	test( 'every module settings pane renders without PHP or console errors', async ( { page } ) => {
		const modules = liteModules();
		expect( modules.length ).toBeGreaterThan( 15 );

		const consoleErrors = [];
		page.on( 'console', ( msg ) => msg.type() === 'error' && consoleErrors.push( msg.text() ) );
		page.on( 'pageerror', ( err ) => consoleErrors.push( err.message ) );

		for ( const module of modules ) {
			await test.step( module.title, async () => {
				const response = await page.goto( `/wp-admin/admin.php?page=romcommerce&tab=modules&category=${ module.category }&module=${ module.id }` );

				expect( response?.status(), module.id ).toBe( 200 );
				expect( await page.content(), module.id ).not.toMatch( PHP_ERROR );
				await expect( page.locator( '#wpbody-content form' ).first(), module.id ).toBeVisible();
			} );
		}

		expect( consoleErrors ).toEqual( [] );
	} );

	test( 'the Placements screen draws every surface', async ( { page } ) => {
		for ( const surface of [ 'product', 'cart', 'checkout', 'site' ] ) {
			await page.goto( `/wp-admin/admin.php?page=romcommerce&tab=placements&surface=${ surface }` );

			expect( await page.content() ).not.toMatch( PHP_ERROR );
			await expect( page.locator( '.rc-pm-tab.is-active' ) ).toHaveCount( 1 );
			await expect( page.locator( '.rc-pm-scene' ) ).toBeVisible();
		}

		// The fixture store builds cart and checkout from shortcodes.
		for ( const surface of [ 'cart', 'checkout' ] ) {
			await page.goto( `/wp-admin/admin.php?page=romcommerce&tab=placements&surface=${ surface }` );
			await expect( page.locator( '.rc-pm-layout' ) ).not.toHaveClass( /is-block/ );
		}

		await page.goto( '/wp-admin/admin.php?page=romcommerce&tab=placements&surface=site' );
		await expect( page.locator( '.rc-pm-zone' ) ).toHaveCount( 3 );
		await expect( page.locator( '.rc-pm-fab' ) ).toBeVisible();
	} );

	test( 'a saved setting reaches the storefront', async ( { page, browser } ) => {
		await page.goto( '/wp-admin/admin.php?page=romcommerce&tab=modules&category=compliance-legal&module=price-history' );
		await page.fill( 'input[name="romcommerce_price_history_text"]', 'Cel mai mic preț (30 zile):' );
		await page.click( '#submit' );
		await expect( page.locator( '.notice-success' ) ).toBeVisible();

		const guest = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const shopper = await guest.newPage();
		await shopper.goto( '/product/produs-redus/' );
		await expect( shopper.locator( '.romcommerce-omnibus-floor' ) ).toContainText( 'Cel mai mic preț (30 zile):' );
		await guest.close();

		await page.fill( 'input[name="romcommerce_price_history_text"]', '' );
		await page.click( '#submit' );
	} );

	test( 'the recommended retail price field saves on the product editor', async ( { page } ) => {
		const id = wp( [ 'eval', "echo get_page_by_path( 'produs-test', OBJECT, 'product' )->ID;" ] );
		await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );

		const field = page.locator( '#romcommerce_prp' );
		await expect( field ).toHaveValue( '130' );
		await field.fill( '140' );
		await page.click( '#publish' );
		await expect( page.locator( '#message.updated' ) ).toBeVisible();
		await expect( field ).toHaveValue( '140' );

		await field.fill( '130' );
		await page.click( '#publish' );
		await expect( page.locator( '#message.updated' ) ).toBeVisible();
	} );

	test( 'MAOF settings show how far the phone index backfill has got', async ( { page } ) => {
		const pane = '/wp-admin/admin.php?page=romcommerce&tab=modules&category=compliance-legal&module=maof';

		setOption( 'romcommerce_maof_phone_index', { complete: false, page: 2, done: 40, total: 160 } );
		await page.goto( pane );
		await expect( page.locator( 'progress.rc-progress' ) ).toHaveAttribute( 'value', '25' );
		await expect( page.locator( 'progress.rc-progress + .description' ) ).toContainText( '40' );
		await page.locator( 'progress.rc-progress' ).screenshot( { path: test.info().outputPath( 'maof-progress.png' ) } );

		const results = await new AxeBuilder( { page } )
			.include( 'progress.rc-progress' )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] )
			.analyze();
		expect( results.violations ).toEqual( [] );

		setOption( 'romcommerce_maof_phone_index', { complete: true, page: 3, done: 160, total: 160 } );
		await page.goto( pane );
		await expect( page.locator( 'progress.rc-progress' ) ).toHaveCount( 0 );
	} );
} );

test.describe( 'EU VAT rates import', () => {
	const cleanup = () => wp( [
		'eval',
		"foreach ( ( get_option( 'romcommerce_vat_rates_import' )['rows'] ?? array() ) as $id ) { WC_Tax::_delete_tax_rate( (int) $id ); } delete_option( 'romcommerce_vat_rates_import' ); RomCommerce\\Settings::set_enabled( 'vat-rates', false );",
	] );
	test.beforeAll( () => {
		cleanup();
		wp( [ 'eval', "RomCommerce\\Settings::set_enabled( 'vat-rates', true );" ] );
	} );
	test.afterAll( cleanup );

	test( 'importing Romania writes its standard and reduced rates', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=romcommerce&tab=modules&category=checkout-billing&module=vat-rates' );
		await page.locator( '#rc-vat-ro' ).check();
		await page.getByRole( 'button', { name: /Import selected rates|Importă/ } ).click();

		await expect( page.locator( '.notice-success' ) ).toContainText( /2/ );
		const rates = JSON.parse( wp( [ 'eval', "echo wp_json_encode( array( WC_Tax::get_rates_for_tax_class( '' ), WC_Tax::get_rates_for_tax_class( 'reduced-rate' ) ) );" ] ) );
		const ro = ( rows ) => Object.values( rows ).filter( ( row ) => 'RO' === row.tax_rate_country ).map( ( row ) => Number( row.tax_rate ) );
		expect( ro( rates[ 0 ] ) ).toEqual( [ 21 ] );
		expect( ro( rates[ 1 ] ) ).toEqual( [ 11 ] );
		await expect( page.locator( '#rc-vat-ro' ) ).toBeChecked();
	} );
} );

test.describe( 'Weekly store report', () => {
	const pane = '/wp-admin/admin.php?page=romcommerce&tab=modules&category=orders-fulfilment&module=store-report';
	test.beforeAll( () => wp( [ 'eval', "RomCommerce\\Settings::set_enabled( 'store-report', true ); delete_option( 'romcommerce_store_report' );" ] ) );
	test.afterAll( () => wp( [ 'eval', "RomCommerce\\Settings::set_enabled( 'store-report', false ); delete_option( 'romcommerce_store_report' ); wp_unschedule_hook( 'romcommerce_store_report_send' );" ] ) );

	test( 'every section is ticked until the merchant unticks one, and the day and hour schedule the next report', async ( { page } ) => {
		await page.goto( pane );

		const sections = page.locator( 'input[name="rc_sections[]"]' );
		await expect( sections ).toHaveCount( 9 );
		for ( const box of await sections.all() ) {
			await expect( box ).toBeChecked();
		}

		await page.locator( '#rc-report-weekday' ).selectOption( '3' );
		await page.locator( '#rc-report-hour' ).selectOption( '9' );
		await page.locator( '#rc-report-compare' ).selectOption( 'year' );
		await page.locator( 'input[name="rc_sections[]"][value="sources"]' ).uncheck();
		await page.locator( '#submit' ).click();
		await expect( page.locator( '.notice-success' ) ).toBeVisible();

		const saved = JSON.parse( wp( [ 'eval', "$s = get_option( 'romcommerce_store_report' ); $next = wp_next_scheduled( 'romcommerce_store_report_send' ); echo wp_json_encode( array( 'sections' => $s['sections'], 'compare' => $s['compare'], 'next' => $next ? wp_date( 'N H:i', $next ) : '' ) );" ] ) );
		expect( saved.sections ).not.toContain( 'sources' );
		expect( saved.sections ).toHaveLength( 8 );
		expect( saved.compare ).toBe( 'year' );
		expect( saved.next ).toBe( '3 09:00' );
		await expect( page.locator( 'input[name="rc_sections[]"][value="sources"]' ) ).not.toBeChecked();
	} );
} );

test.describe( 'Free shipping bar shape and thickness', () => {
	const pane = '/wp-admin/admin.php?page=romcommerce&tab=modules&category=storefront-merchandising&module=free-shipping-bar';
	let saved;
	test.beforeAll( () => {
		saved = wp( [ 'eval', "echo wp_json_encode( get_option( 'romcommerce_free_shipping_bar', null ) );" ] );
	} );
	test.afterAll( () => {
		if ( 'null' === saved ) {
			wp( [ 'option', 'delete', 'romcommerce_free_shipping_bar' ] );
		} else {
			setOption( 'romcommerce_free_shipping_bar', JSON.parse( saved ) );
		}
	} );

	test( 'a square 2px bar is saved and drawn on the cart', async ( { page } ) => {
		await page.goto( pane );
		await page.locator( '#rc-fsb-shape' ).selectOption( 'square' );
		await page.locator( '#rc-fsb-thickness' ).fill( '2' );
		await page.locator( '#submit' ).click();
		await expect( page.locator( '.notice-success' ) ).toBeVisible();
		await expect( page.locator( '#rc-fsb-thickness' ) ).toHaveValue( '2' );

		await addToCart( page, 'produs-test' );
		await page.goto( '/cart/' );
		const track = page.locator( '.romcommerce-fsb-track' ).first();
		await expect( track ).toHaveCSS( 'height', '2px' );
		await expect( track ).toHaveCSS( 'border-radius', '0px' );
	} );
} );

test.describe( 'Contact FAB icons and shape', () => {
	const pane = '/wp-admin/admin.php?page=romcommerce&tab=modules&category=storefront-merchandising&module=whatsapp-fab';
	let saved;
	test.beforeAll( () => {
		saved = wp( [ 'option', 'get', 'romcommerce_whatsapp_fab', '--format=json' ] );
	} );
	test.afterAll( () => setOption( 'romcommerce_whatsapp_fab', JSON.parse( saved ) ) );

	test( 'the dashicon picker fills the field and the chosen shape reaches the storefront', async ( { page } ) => {
		await page.goto( pane );

		await page.locator( 'select[name="rc_channel[facebook][icon_type]"]' ).selectOption( 'dashicon' );
		const browse = page.locator( '.rc-dashicon-browse[data-rc-dashicon-target="rc-dashicon-facebook"]' );
		await browse.click();
		await expect( browse ).toHaveAttribute( 'aria-expanded', 'true' );

		const picker = page.locator( '#rc-dashicon-picker' );
		await expect( picker.locator( '.rc-dashicon-pick' ).first() ).toBeVisible();
		await page.locator( '#rc-dashicon-search' ).fill( 'cart' );
		await expect( picker.locator( '.rc-dashicon-pick[data-rc-dashicon="dashicons-admin-users"]' ) ).toBeHidden();
		await picker.locator( '.rc-dashicon-pick[data-rc-dashicon="dashicons-cart"]' ).click();

		await expect( page.locator( '#rc-dashicon-facebook' ) ).toHaveValue( 'dashicons-cart' );
		await expect( picker ).toBeHidden();

		await page.locator( '#rc-fab-shape' ).selectOption( 'square' );
		await page.locator( '#submit' ).click();
		await expect( page.locator( '.notice-success' ) ).toBeVisible();

		const stored = JSON.parse( wp( [ 'option', 'get', 'romcommerce_whatsapp_fab', '--format=json' ] ) );
		expect( stored.shape ).toBe( 'square' );
		expect( stored.channels.facebook.icon_dashicon ).toBe( 'dashicons-cart' );

		await page.goto( '/product/produs-test/' );
		await expect( page.locator( '.romcommerce-fab-toggle' ) ).toHaveCSS( 'border-radius', '0px' );
	} );
} );

test.describe( 'Payment method filter on the orders list', () => {
	let orders;
	test.beforeAll( () => {
		orders = JSON.parse(
			wp( [ 'eval', "RomCommerce\\Settings::set_enabled( 'order-payment-filter', true ); $ids = array(); foreach ( array( 'cod', 'bacs' ) as $method ) { $o = wc_create_order(); $o->set_payment_method( $method ); $o->set_billing_last_name( 'Filtru-' . $method ); $o->save(); $ids[ $method ] = $o->get_id(); } $ids['url'] = Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled() ? 'admin.php?page=wc-orders' : 'edit.php?post_type=shop_order'; echo wp_json_encode( $ids );" ] )
		);
	} );
	test.afterAll( () => wp( [ 'eval', `RomCommerce\\Settings::set_enabled( 'order-payment-filter', false ); foreach ( array( ${ orders.cod }, ${ orders.bacs } ) as $id ) { wc_get_order( $id )->delete( true ); }` ] ) );

	test( 'choosing a method lists only the orders paid with it', async ( { page } ) => {
		await page.goto( `/wp-admin/${ orders.url }` );
		const select = page.locator( '#filter-by-rc-payment-method' );
		await expect( select ).toBeVisible();
		await expect( select.locator( 'option[value="bacs"]' ) ).toHaveCount( 1 );

		await select.selectOption( 'cod' );
		await page.locator( '#order-query-submit, #post-query-submit' ).first().click();

		await expect( page.locator( '#filter-by-rc-payment-method' ) ).toHaveValue( 'cod' );
		await expect( page.getByText( 'Filtru-cod' ).first() ).toBeVisible();
		await expect( page.getByText( 'Filtru-bacs' ) ).toHaveCount( 0 );
	} );
} );

test.describe( 'Frequently bought together on the product editor', () => {
	const reset = ( on ) => wp( [ 'eval', `delete_post_meta_by_key( '_romcommerce_fbt_ids' ); RomCommerce\\Settings::set_enabled( 'frequently-bought-together', ${ on ? 'true' : 'false' } );` ] );
	test.beforeAll( () => reset( true ) );
	test.afterAll( () => reset( false ) );

	test( 'products picked under Linked Products are saved', async ( { page } ) => {
		const id = wp( [ 'eval', "echo get_page_by_path( 'produs-test', OBJECT, 'product' )->ID;" ] );
		await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );

		await page.locator( '.linked_product_options a' ).click();
		// Typed key by key: on CI, selectWoo missed fill()'s lone input event and never searched.
		const search = page.locator( '#romcommerce_fbt_ids + .select2 .select2-search__field' );
		await search.click();
		await search.pressSequentially( 'redus' );
		await page.locator( '.select2-results__option', { hasText: 'Produs redus' } ).click();
		await page.click( '#publish' );
		await expect( page.locator( '#message.updated' ) ).toBeVisible();

		const redus = wp( [ 'eval', "echo get_page_by_path( 'produs-redus', OBJECT, 'product' )->ID;" ] );
		expect( wp( [ 'eval', `echo wp_json_encode( get_post_meta( ${ id }, '_romcommerce_fbt_ids', true ) );` ] ) ).toBe( `[${ redus }]` );
	} );
} );

test.describe( 'Admin branding over the Modern colour scheme', () => {
	// The fixture's brand colour (#c0392b) and its lighten( 0.35 ) hover shade.
	const brand = 'rgb(192, 57, 43)';
	const hover = 'rgb(214, 126, 117)';
	let scheme;
	test.beforeAll( () => {
		scheme = wp( [ 'eval', "echo get_user_meta( get_user_by( 'login', 'admin' )->ID, 'admin_color', true );" ] );
		wp( [ 'user', 'meta', 'update', 'admin', 'admin_color', 'modern' ] );
	} );
	test.afterAll( () => {
		if ( scheme ) {
			wp( [ 'user', 'meta', 'update', 'admin', 'admin_color', scheme ] );
		} else {
			wp( [ 'user', 'meta', 'delete', 'admin', 'admin_color' ] );
		}
	} );

	test( 'the brand colour outranks the scheme on submenu hover, the admin bar and theme-colour buttons', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php' );
		await expect( page.locator( '.page-title-action' ) ).toHaveCSS( 'border-top-color', brand );

		const submenuLink = page.locator( '#menu-posts .wp-submenu a' ).nth( 1 );
		await submenuLink.hover();
		await expect( submenuLink ).toHaveCSS( 'color', hover );

		const newContent = page.locator( '#wp-admin-bar-new-content' );
		await newContent.hover();
		await expect( newContent.locator( '.ab-label' ) ).toHaveCSS( 'color', hover );
	} );
} );
