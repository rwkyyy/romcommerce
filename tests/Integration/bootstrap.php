<?php
/**
 * Integration-test bootstrap: boots real WordPress + WooCommerce + the built
 * RomCommerce release copy inside the wp-env tests container.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

$romcommerce_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/wordpress-phpunit';

require_once __DIR__ . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $romcommerce_tests_dir . '/includes/functions.php';

/**
 * ROMCOMMERCE_TEST_HPOS=1 runs the whole suite against WooCommerce's custom
 * order tables; unset runs it against legacy post storage. CI runs both, since
 * every order-touching module has to work on either (CLAUDE.md CRUD rule).
 */
function romcommerce_tests_hpos(): bool {
	return '1' === getenv( 'ROMCOMMERCE_TEST_HPOS' );
}

/**
 * Every module that can be toggled, enabled up front. Modules boot once per
 * PHP process at plugins_loaded, so they must already be on before RomCommerce
 * loads; a test can't switch one on later and expect its hooks to exist.
 */
function romcommerce_tests_enable_all_modules(): void {
	$ids = array(
		'admin-branding',
		'clear-cart',
		'counties-postcodes',
		'coupon-cleanup',
		'footer-credit',
		'free-shipping-bar',
		'frequently-bought-together',
		'hide-voucher',
		'legal-guarantee-notice',
		'maof',
		'merchant-widget',
		'multi-address',
		'order-payment-filter',
		'availability-sorting',
		'perks-shop',
		'pf-pj-billing',
		'store-report',
		'phone-validation',
		'price-history',
		'recommended-price',
		'related-products',
		'review-page',
		'reviewer-names',
		'reviews-showcase',
		'sal-pictogram',
		'sale-category-sync',
		'vat-id-validation',
		'vat-rates',
		'whatsapp-fab',
	);

	update_option( 'romcommerce_core', array( 'enabled_modules' => array_fill_keys( $ids, true ) ), true );
}

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		romcommerce_tests_enable_all_modules();

		require WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		require WP_PLUGIN_DIR . '/romcommerce/romcommerce.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		WC_Install::install();

		// WordPress's test installer resets only core tables, so the price log
		// would otherwise keep rows from earlier runs, and a product reusing an
		// old ID would inherit that history.
		RomCommerce\Modules\PriceHistory\Log::ensure_table();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . RomCommerce\Modules\PriceHistory\Log::table_name() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// WC_Install picks an order storage default for fresh installs, so the
		// requested mode is forced afterwards rather than before.
		update_option( 'woocommerce_custom_orders_table_enabled', romcommerce_tests_hpos() ? 'yes' : 'no' );
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );

		if ( romcommerce_tests_hpos() ) {
			wc_get_container()
				->get( Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )
				->create_database_tables();
		}

		// Roles are cached before WC_Install adds the shop roles.
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- standard WC test-bootstrap reset.
		wp_roles();
	}
);

require $romcommerce_tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/TestCase.php';
