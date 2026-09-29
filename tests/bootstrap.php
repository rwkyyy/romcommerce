<?php
/**
 * PHPUnit bootstrap. Loads Composer's autoloader and RomCommerce's own PSR-4
 * autoloader (no WordPress install involved — see tests/TestCase.php for how
 * individual tests stand in for WordPress and WooCommerce functions via
 * Brain Monkey).
 *
 * @package RomCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// WP core time constants (wp-includes/default-constants.php) — plain
// constants, not functions, so Brain Monkey can't stub them per test.
foreach (
	array(
		'MINUTE_IN_SECONDS' => 60,
		'HOUR_IN_SECONDS'   => 60 * 60,
		'DAY_IN_SECONDS'    => 60 * 60 * 24,
		'WEEK_IN_SECONDS'   => 60 * 60 * 24 * 7,
		'MONTH_IN_SECONDS'  => 60 * 60 * 24 * 30,
		'YEAR_IN_SECONDS'   => 60 * 60 * 24 * 365,
		// wp-includes/wp-db.php: a $wpdb::get_results() output-format flag,
		// not a function, so Brain Monkey can't stub it per test either.
		'ARRAY_A'           => 'ARRAY_A',
	) as $constant => $value
) {
	if ( ! defined( $constant ) ) {
		define( $constant, $value );
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/src/autoload.php';

// romcommerce.php itself is never require'd here (it would try to boot a real
// plugin lifecycle) but a few modules resolve real on-disk assets (e.g. the
// EU GARAN label SVGs) via ROMCOMMERCE_DIR, so it's defined standalone,
// pointing at the same plugin root romcommerce.php itself would use.
if ( ! defined( 'ROMCOMMERCE_DIR' ) ) {
	define( 'ROMCOMMERCE_DIR', dirname( __DIR__ ) );
}
