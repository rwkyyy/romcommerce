<?php
/**
 * Prints what the upgrade check compares before and after an update: the
 * modules that report themselves enabled, plus the URLs bin/upgrade-check.sh
 * fetches to see what actually renders. Uses only API every released version
 * has, since it runs under the previous release too.
 *
 * Run via: wp eval-file wp-content/romcommerce-tests/upgrade/snapshot.php
 *
 * No declare(strict_types=1): wp eval-file runs this through eval().
 *
 * @package RomCommerce
 */

$romcommerce_enabled = array();
foreach ( \RomCommerce\Plugin::instance()->modules()->all() as $romcommerce_id => $romcommerce_module ) {
	if ( $romcommerce_module->is_enabled() ) {
		$romcommerce_enabled[] = (string) $romcommerce_id;
	}
}
sort( $romcommerce_enabled );

$romcommerce_product = get_page_by_path( 'produs-promo', OBJECT, 'product' );

echo 'SNAPSHOT:' . wp_json_encode(
	array(
		'version' => defined( 'ROMCOMMERCE_VERSION' ) ? ROMCOMMERCE_VERSION : '',
		'plugin'  => basename( dirname( ( new ReflectionClass( \RomCommerce\Plugin::class ) )->getFileName(), 2 ) ),
		'modules' => $romcommerce_enabled,
		'urls'    => array(
			'home'     => home_url( '/' ),
			'product'  => $romcommerce_product ? get_permalink( $romcommerce_product ) : '',
			'cart'     => wc_get_cart_url(),
			'checkout' => wc_get_checkout_url(),
		),
		'add_to_cart' => $romcommerce_product ? $romcommerce_product->ID : 0,
	)
) . "\n";
