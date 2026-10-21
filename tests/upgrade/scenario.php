<?php
/**
 * Puts the previous release, freshly activated, into one of the upgrade
 * check's starting states. Runs under whichever RomCommerce copy is active,
 * so it only uses API that every released version has: Plugin::instance(),
 * ModuleLoader::all() and Settings::set_enabled().
 *
 * Run via: wp eval-file wp-content/romcommerce-tests/upgrade/scenario.php <untouched|configured>
 *
 * - untouched: the merchant activated the plugin and never saved anything.
 *   Catches modules whose implicit default changes on update.
 * - configured: every module switched on, settings left at their defaults.
 *   Catches modules whose default settings change on update.
 *
 * No declare(strict_types=1): wp eval-file runs this through eval().
 *
 * @package RomCommerce
 */

$romcommerce_scenario = $args[0] ?? 'untouched';

if ( 'configured' === $romcommerce_scenario ) {
	foreach ( array_keys( \RomCommerce\Plugin::instance()->modules()->all() ) as $romcommerce_module_id ) {
		\RomCommerce\Settings::set_enabled( (string) $romcommerce_module_id, true );
	}
}

update_option( 'romcommerce_welcome_notice_dismissed', true, false );

WP_CLI::success( "Scenario ready ({$romcommerce_scenario})." );
