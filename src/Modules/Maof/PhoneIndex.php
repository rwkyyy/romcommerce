<?php

declare(strict_types=1);

namespace RomCommerce\Modules\Maof;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a normalised copy of each order's billing phone in order meta, so the
 * finder can match "0712 345 678" to "+40712345678" with one exact query.
 * WooCommerce stores the phone as typed and wc_get_orders() only matches it
 * exactly. The typed value is never rewritten: invoices, couriers and e-mails
 * read it as entered.
 *
 * The copy is personal data derived for MAOF's purpose only, so it lives
 * exactly as long as the module is on: written on every order save, backfilled
 * over the 12-month window when the module is enabled, cleared when the billing
 * phone is or WooCommerce's privacy eraser anonymises the order, and purged
 * from every order when the module is switched off or the plugin is
 * uninstalled.
 */
final class PhoneIndex {

	public const META_KEY = '_romcommerce_phone_e164';

	/** Custom wc_get_orders() arg for the legacy store; see query_args(). */
	public const QUERY_VAR = 'romcommerce_phone_e164';

	public const BACKFILL_HOOK = 'romcommerce_maof_phone_backfill';

	private const STATE_OPTION = 'romcommerce_maof_phone_index';

	private const BATCH_SIZE = 100;

	public function register(): void {
		add_action( 'woocommerce_before_order_object_save', array( $this, 'sync' ) );
		add_action( 'init', array( $this, 'schedule_backfill' ) );
		add_action( self::BACKFILL_HOOK, array( $this, 'backfill_batch' ) );
		add_action( 'romcommerce/module_toggled', array( $this, 'on_module_toggled' ), 10, 2 );
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'restore_legacy_meta_query' ), 10, 2 );
	}

	/**
	 * Fires before the data store writes, so the meta change is persisted by the
	 * same save rather than a second one.
	 *
	 * @param mixed $order
	 */
	public function sync( $order ): void {
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
			return;
		}

		$this->apply( $order );
	}

	/** @return bool whether the order's meta changed. */
	private function apply( WC_Order $order ): bool {
		// WooCommerce's privacy eraser replaces the phone with a placeholder
		// rather than blanking it; indexing that would link every erased order.
		$normalized = 'yes' === $order->get_meta( '_anonymized' ) ? '' : Finder::normalize_phone( $order->get_billing_phone() );
		$stored     = (string) $order->get_meta( self::META_KEY );

		if ( $normalized === $stored ) {
			return false;
		}

		if ( '' === $normalized ) {
			$order->delete_meta_data( self::META_KEY );
		} else {
			$order->update_meta_data( self::META_KEY, $normalized );
		}

		return true;
	}

	/**
	 * Same self-healing pattern as Coupon Cleanup's reconcile_cron(): the
	 * file-deploy workflow never fires an activation hook, so the check runs on
	 * init. One autoloaded option read while the backfill is complete.
	 */
	public function schedule_backfill(): void {
		if ( $this->state()['complete'] || false !== wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time(), self::BACKFILL_HOOK );
	}

	/**
	 * One batch per cron run, oldest order first. Paging by ID is stable: new
	 * orders append at the end, and orders ageing out of the window only shift
	 * pages by orders the finder no longer looks at anyway.
	 */
	public function backfill_batch(): void {
		$state = $this->state();
		if ( $state['complete'] ) {
			return;
		}

		// paginate also returns the window's order count, which the settings
		// panel's progress bar needs; one COUNT query per batch.
		$result = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'limit'        => self::BATCH_SIZE,
				'paged'        => $state['page'],
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'date_created' => '>' . ( time() - Finder::LOOKBACK_MONTHS * MONTH_IN_SECONDS ),
				'paginate'     => true,
			)
		);
		$orders = is_object( $result ) && isset( $result->orders ) && is_array( $result->orders ) ? $result->orders : array();
		$total  = is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;

		foreach ( $orders as $order ) {
			// save_meta_data(), not save(): a full save would fire every
			// order-save hook on the site for a change only MAOF reads.
			if ( $order instanceof WC_Order && $this->apply( $order ) ) {
				$order->save_meta_data();
			}
		}

		$complete = count( $orders ) < self::BATCH_SIZE;
		update_option(
			self::STATE_OPTION,
			array(
				'complete' => $complete,
				'page'     => $state['page'] + 1,
				'done'     => ( $state['page'] - 1 ) * self::BATCH_SIZE + count( $orders ),
				'total'    => $total,
			),
			true
		);

		if ( ! $complete ) {
			wp_schedule_single_event( time(), self::BACKFILL_HOOK );
		}
	}

	/**
	 * Disabling always happens on a request where the module was still enabled
	 * and therefore booted, so this listener is attached when it matters.
	 */
	public function on_module_toggled( string $module_id, bool $enabled ): void {
		if ( 'maof' === $module_id && ! $enabled ) {
			self::purge();
		}
	}

	/**
	 * Removes every copy and resets the backfill, so re-enabling starts clean.
	 * Deletes by meta key in both order stores directly: going through CRUD
	 * would mean loading every order on the site. Both tables are cleared
	 * because HPOS compatibility mode keeps meta in each.
	 */
	public static function purge(): void {
		global $wpdb;

		wp_clear_scheduled_hook( self::BACKFILL_HOOK );
		delete_option( self::STATE_OPTION );
		delete_post_meta_by_key( self::META_KEY );

		$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- existence check for WooCommerce's HPOS meta table; nothing to cache.
		if ( $hpos_meta === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $hpos_meta ) ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bulk delete of one meta key across all orders; see the docblock.
			$wpdb->delete( $hpos_meta, array( 'meta_key' => self::META_KEY ) );
		}
	}

	/**
	 * wc_get_orders() args that match orders by their indexed phone. HPOS
	 * takes a meta_query; the legacy post store doesn't (7.0 drops it silently,
	 * 9.2+ also raises a doing-it-wrong notice), so there the value travels as
	 * a custom query var that restore_legacy_meta_query() turns into WP_Query
	 * meta args, the hook WooCommerce documents for custom order queries.
	 *
	 * @return array<string, mixed>
	 */
	public static function query_args( string $e164 ): array {
		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return array( 'meta_query' => array( self::clause( $e164 ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- single-key exact match on the phone index.
		}

		return array( self::QUERY_VAR => $e164 );
	}

	/**
	 * @param array<string, mixed> $wp_query_args
	 * @param array<string, mixed> $query_vars
	 * @return array<string, mixed>
	 */
	public function restore_legacy_meta_query( array $wp_query_args, array $query_vars ): array {
		if ( empty( $query_vars[ self::QUERY_VAR ] ) ) {
			return $wp_query_args;
		}

		$meta_query                  = isset( $wp_query_args['meta_query'] ) && is_array( $wp_query_args['meta_query'] ) ? $wp_query_args['meta_query'] : array();
		$meta_query[]                = self::clause( (string) $query_vars[ self::QUERY_VAR ] );
		$wp_query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- single-key exact match on the phone index.

		return $wp_query_args;
	}

	/** @return array{key: string, value: string, compare: string} */
	public static function clause( string $e164 ): array {
		return array(
			'key'     => self::META_KEY,
			'value'   => $e164,
			'compare' => '=',
		);
	}

	/**
	 * Backfill progress for the settings panel. total is null until the first
	 * batch has run and counted the window.
	 *
	 * @return array{complete: bool, done: int, total: ?int}
	 */
	public static function progress(): array {
		$state = ( new self() )->state();

		return array(
			'complete' => $state['complete'],
			'done'     => min( $state['done'], (int) $state['total'] ),
			'total'    => $state['total'],
		);
	}

	/** @return array{complete: bool, page: int, done: int, total: ?int} */
	private function state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'complete' => ! empty( $state['complete'] ),
			'page'     => max( 1, (int) ( $state['page'] ?? 1 ) ),
			'done'     => max( 0, (int) ( $state['done'] ?? 0 ) ),
			'total'    => isset( $state['total'] ) ? max( 0, (int) $state['total'] ) : null,
		);
	}
}
