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
	) as $constant => $value
) {
	if ( ! defined( $constant ) ) {
		define( $constant, $value );
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/src/autoload.php';
