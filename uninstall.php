<?php
/**
 * Uninstall cleanup for RomCommerce.
 *
 * By default this removes only the plugin's own settings/options and its
 * derived data (MAOF's phone index, Price History's cached reference prices).
 * User- and customer-owned data held in WordPress-native stores is
 * deliberately preserved: the Omnibus price-history log can be a
 * legally relevant record, and saved delivery addresses belong to the
 * customer. Destroying those during an uninstall a merchant might run for
 * unrelated reasons is the greater harm.
 *
 * The General settings screen exposes an explicit, off-by-default "Delete all
 * RomCommerce data when the plugin is deleted" opt-in. When a merchant has turned
 * it on, this file additionally purges those data stores. Product reviews are
 * never touched — they are first-class WooCommerce reviews indistinguishable from
 * ones left through a product page, not RomCommerce-private data.
 *
 * @package RomCommerce
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Full cleanup for the current site: read the opt-in flag, purge data stores if
 * it is set, then always delete the plugin's own options. The flag lives inside
 * the autoloaded romcommerce_core option; it must be read before the options are
 * deleted, and per-site because each blog carries its own core option. The key
 * string mirrors RomCommerce\Settings::delete_data_on_uninstall() — uninstall.php
 * runs standalone and can't rely on the plugin autoloader, so it reads the raw
 * option here.
 */
function romcommerce_uninstall_site(): void {
	$core = get_option( 'romcommerce_core', array() );

	if ( is_array( $core ) && ! empty( $core['delete_data_on_uninstall'] ) ) {
		romcommerce_uninstall_delete_data();
	}

	romcommerce_uninstall_clear_cron();
	romcommerce_uninstall_delete_maof_phone_index();
	// Mirrors PriceHistory\Reference::META_KEY: a cache, rebuilt from the log.
	delete_post_meta_by_key( '_romcommerce_omnibus_reference' );
	romcommerce_uninstall_delete_options();
}

/**
 * The plugin's WP-Cron events, which would otherwise stay in the cron option
 * with nothing listening. Mirrors the HOOK constants in the modules.
 */
function romcommerce_uninstall_clear_cron(): void {
	$hooks = array(
		'romcommerce_trash_expired_coupons',
		'romcommerce_empty_coupon_trash',
		'romcommerce_maof_phone_backfill',
		'romcommerce_price_history_baseline',
		'romcommerce_store_report_send',
	);

	foreach ( $hooks as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
}

/**
 * Always removed, whatever the opt-in says: MAOF's normalised phone copies are
 * derived data with no use outside the plugin, unlike the merchant's or the
 * customer's own records. Mirrors RomCommerce\Modules\Maof\PhoneIndex::purge()
 * (same meta key, both order stores), which this standalone file can't load.
 */
function romcommerce_uninstall_delete_maof_phone_index(): void {
	global $wpdb;

	$meta_key = '_romcommerce_phone_e164';
	delete_post_meta_by_key( $meta_key );

	$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup; no cache is relevant.
	if ( $hpos_meta === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $hpos_meta ) ) ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time uninstall cleanup of a single meta key.
		$wpdb->delete( $hpos_meta, array( 'meta_key' => $meta_key ) );
	}
}

/**
 * Delete every option this plugin created on the current site. All RomCommerce
 * options are romcommerce_-prefixed (romcommerce_core, romcommerce_{module},
 * romcommerce_price_history_db).
 */
function romcommerce_uninstall_delete_options(): void {
	global $wpdb;

	$like = $wpdb->esc_like( 'romcommerce_' ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup; no cache is relevant.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
}

/**
 * Purge RomCommerce's user-facing data stores. Runs only on the explicit opt-in.
 * Deletes the price-history table, saved delivery addresses, and the
 * recommended retail prices and "frequently bought together" picks entered on
 * products. Dropping the
 * price-history table is safe because its romcommerce_price_history_db version
 * marker is removed with the options above, so a later reinstall recreates the
 * table from scratch.
 */
function romcommerce_uninstall_delete_data(): void {
	global $wpdb;

	$table = $wpdb->prefix . 'romcommerce_price_history';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- dropping the plugin's own table on uninstall is the intended schema change; the name derives from $wpdb->prefix, not user input, and %i (identifier placeholder) needs WP 6.2, above the 6.0 floor.
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

	delete_metadata( 'user', 0, 'romcommerce_delivery_addresses', '', true );
	delete_post_meta_by_key( '_romcommerce_prp' );
	delete_post_meta_by_key( '_romcommerce_fbt_ids' );
}

if ( is_multisite() ) {
	$romcommerce_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $romcommerce_site_ids as $romcommerce_site_id ) {
		switch_to_blog( (int) $romcommerce_site_id );
		romcommerce_uninstall_site();
		restore_current_blog();
	}
} else {
	romcommerce_uninstall_site();
}
