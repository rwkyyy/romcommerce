<?php
/**
 * Puts the wp-env development site into a known Romanian-shop state for the
 * browser tests. Idempotent: safe to run before every test run.
 *
 * Run via: wp eval-file wp-content/romcommerce-tests/e2e/fixtures/store.php <theme> [classic|blocks]
 *
 * The second argument picks the cart and checkout pages: shortcodes (default)
 * or WooCommerce's own block content, the default for new stores.
 *
 * No declare(strict_types=1): wp eval-file runs this through eval(), where
 * that declaration is a fatal error.
 *
 * @package RomCommerce
 */

$romcommerce_theme    = $args[0] ?? 'storefront';
$romcommerce_checkout = $args[1] ?? 'classic';

switch_theme( $romcommerce_theme );

update_option( 'permalink_structure', '/%postname%/' );
update_option( 'blogname', 'Magazin Test' );

update_option( 'woocommerce_default_country', 'RO:B' );
update_option( 'woocommerce_currency', 'RON' );
update_option( 'woocommerce_price_num_decimals', '2' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_ship_to_countries', 'specific' );
update_option( 'woocommerce_specific_ship_to_countries', array( 'RO' ) );
update_option( 'woocommerce_allowed_countries', 'specific' );
update_option( 'woocommerce_specific_allowed_countries', array( 'RO' ) );
update_option(
	'woocommerce_cod_settings',
	array(
		'enabled'     => 'yes',
		'title'       => 'Ramburs',
		'description' => '',
	)
);

function romcommerce_e2e_page( string $option, string $title, string $content ): void {
	$page_id = (int) get_option( $option );
	$page    = $page_id ? get_post( $page_id ) : null;

	$data = array(
		'post_title'   => $title,
		'post_content' => $content,
		'post_status'  => 'publish',
		'post_type'    => 'page',
	);

	if ( $page instanceof WP_Post ) {
		$data['ID'] = $page->ID;
		wp_update_post( $data );
		return;
	}

	update_option( $option, wp_insert_post( $data ) );
}

/**
 * WooCommerce's install-time block content for the cart or checkout page, read
 * through reflection so it always matches the WooCommerce version under test.
 */
function romcommerce_e2e_block_content( string $page ): string {
	$method = new ReflectionMethod( 'WC_Install', 'get_' . $page . '_block_content' );
	$method->setAccessible( true );

	return (string) $method->invoke( null );
}

if ( 'blocks' === $romcommerce_checkout ) {
	romcommerce_e2e_page( 'woocommerce_cart_page_id', 'Cos', romcommerce_e2e_block_content( 'cart' ) );
	romcommerce_e2e_page( 'woocommerce_checkout_page_id', 'Finalizare comanda', romcommerce_e2e_block_content( 'checkout' ) );
} else {
	romcommerce_e2e_page( 'woocommerce_cart_page_id', 'Cos', '[woocommerce_cart]' );
	romcommerce_e2e_page( 'woocommerce_checkout_page_id', 'Finalizare comanda', '[woocommerce_checkout]' );
}
romcommerce_e2e_page( 'woocommerce_myaccount_page_id', 'Contul meu', '[woocommerce_my_account]' );

$romcommerce_zone_id = 0;
foreach ( WC_Shipping_Zones::get_zones() as $romcommerce_zone ) {
	if ( 'Romania' === $romcommerce_zone['zone_name'] ) {
		$romcommerce_zone_id = (int) $romcommerce_zone['id'];
	}
}
if ( 0 === $romcommerce_zone_id ) {
	$romcommerce_new_zone = new WC_Shipping_Zone();
	$romcommerce_new_zone->set_zone_name( 'Romania' );
	$romcommerce_new_zone->add_location( 'RO', 'country' );
	$romcommerce_new_zone->save();
	$romcommerce_new_zone->add_shipping_method( 'flat_rate' );
	$romcommerce_new_zone->add_shipping_method( 'free_shipping' );

	foreach ( $romcommerce_new_zone->get_shipping_methods() as $romcommerce_method ) {
		$romcommerce_key = 'woocommerce_' . $romcommerce_method->id . '_' . $romcommerce_method->instance_id . '_settings';
		if ( 'flat_rate' === $romcommerce_method->id ) {
			$romcommerce_settings = array(
				'title'      => 'Curier',
				'cost'       => '20',
				'tax_status' => 'none',
			);
		} else {
			$romcommerce_settings = array(
				'title'      => 'Livrare gratuita',
				'requires'   => 'min_amount',
				'min_amount' => '300',
			);
		}
		update_option( $romcommerce_key, $romcommerce_settings );
	}
}

/**
 * @return int product id, created once and reset to the given prices.
 */
function romcommerce_e2e_product( string $slug, string $name, string $regular, string $sale = '' ): int {
	$existing = get_page_by_path( $slug, OBJECT, 'product' );
	$product  = $existing ? wc_get_product( $existing->ID ) : new WC_Product_Simple();

	$product->set_name( $name );
	$product->set_slug( $slug );
	$product->set_status( 'publish' );
	$product->set_regular_price( $regular );
	$product->set_sale_price( $sale );
	$product->save();

	return $product->get_id();
}

$romcommerce_full_price = romcommerce_e2e_product( 'produs-test', 'Produs test', '100' );
$romcommerce_reduced    = romcommerce_e2e_product( 'produs-redus', 'Produs redus', '100', '80' );
$romcommerce_promo      = romcommerce_e2e_product( 'produs-promo', 'Produs promo', '150', '80' );

// Sorts first by name, so availability sorting has something to move to the end.
$romcommerce_sold_out = wc_get_product( romcommerce_e2e_product( 'produs-epuizat', 'Aaa produs epuizat', '90' ) );
$romcommerce_sold_out->set_stock_status( 'outofstock' );
$romcommerce_sold_out->save();

/**
 * Variable product for the WhatsApp variation link. Created once: rebuilding
 * its variations on every run would only churn ids the tests don't depend on.
 */
function romcommerce_e2e_variable_product(): void {
	if ( get_page_by_path( 'produs-variabil', OBJECT, 'product' ) ) {
		return;
	}

	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Marime' );
	$attribute->set_options( array( 'S', 'M' ) );
	$attribute->set_visible( true );
	$attribute->set_variation( true );

	$product = new WC_Product_Variable();
	$product->set_name( 'Produs variabil' );
	$product->set_slug( 'produs-variabil' );
	$product->set_status( 'publish' );
	$product->set_attributes( array( $attribute ) );
	$product->save();

	foreach ( array( 'S', 'M' ) as $size ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'marime' => $size ) );
		$variation->set_regular_price( '50' );
		$variation->save();
	}

	WC_Product_Variable::sync( $product->get_id() );
}

romcommerce_e2e_variable_product();

update_post_meta( $romcommerce_full_price, '_romcommerce_prp', '130' );
update_post_meta( $romcommerce_promo, '_romcommerce_prp', '180' );

/**
 * Rewritten on every run so the dates stay relative to today. produs-redus
 * has nothing on record before its 80 sale; produs-promo has the owner's
 * worked example: 150, a 100 promotion 20 days ago, back to 150, then 80.
 *
 * @param array<int, array{0: string, 1: int}> $history price => seconds ago, oldest first.
 */
function romcommerce_e2e_history( int $product_id, array $history ): void {
	global $wpdb;

	\RomCommerce\Modules\PriceHistory\Log::ensure_table();
	$wpdb->delete( \RomCommerce\Modules\PriceHistory\Log::table_name(), array( 'product_id' => $product_id ) );
	delete_post_meta( $product_id, '_romcommerce_omnibus_reference' );

	foreach ( $history as $entry ) {
		\RomCommerce\Modules\PriceHistory\Log::insert( $product_id, $entry[0], gmdate( 'Y-m-d H:i:s', time() - $entry[1] ) );
	}
}

if ( class_exists( \RomCommerce\Modules\PriceHistory\Log::class ) ) {
	romcommerce_e2e_history( $romcommerce_reduced, array( array( '80', HOUR_IN_SECONDS ) ) );
	romcommerce_e2e_history(
		$romcommerce_promo,
		array(
			array( '150', 60 * DAY_IN_SECONDS ),
			array( '100', 20 * DAY_IN_SECONDS ),
			array( '150', 10 * DAY_IN_SECONDS ),
			array( '80', HOUR_IN_SECONDS ),
		)
	);
}

update_option(
	'romcommerce_core',
	array(
		'enabled_modules' => array_fill_keys(
			array(
				'admin-branding',
				'clear-cart',
				'counties-postcodes',
				'free-shipping-bar',
				'legal-guarantee-notice',
				'maof',
				'pf-pj-billing',
				'price-history',
				'recommended-price',
				'sal-pictogram',
				'vat-id-validation',
				'whatsapp-fab',
			),
			true
		),
	),
	true
);
update_option( 'romcommerce_welcome_notice_dismissed', true, false );
update_option( 'romcommerce_admin_branding', array( 'color' => '#c0392b' ), false );
update_option(
	'romcommerce_whatsapp_fab',
	array(
		'position'   => 'left',
		'atc_button' => true,
		'channels'   => array(
			'whatsapp'  => array(
				'enabled' => true,
				'value'   => '40712345678',
			),
			'phone'     => array(
				'enabled' => true,
				'value'   => '0712345678',
			),
			'messenger' => array(
				'enabled' => true,
				'value'   => 'https://m.me/magazintest',
			),
		),
	),
	false
);

// WP only writes .htaccess when it can detect mod_rewrite, which it can't from
// the CLI; the wp-env Apache container does have it.
add_filter( 'got_rewrite', '__return_true' );
flush_rewrite_rules( true );

WP_CLI::success( "Store ready ({$romcommerce_theme})." );
