<?php

declare(strict_types=1);

namespace RomCommerce\Modules\OrderPaymentFilter;

use Automattic\WooCommerce\Utilities\OrderUtil;
use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;
use WC_Order;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * A payment method dropdown on WooCommerce's orders list, next to its date
 * and customer filters, on both order storages. Admin-only: nothing runs on
 * the storefront or at checkout.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'order-payment-filter';

	/** Prefixed so a payment filter WooCommerce adds later under a plain name can't collide with it. */
	public const QUERY_VAR = 'rc_payment_method';

	private const USED_CACHE = 'romcommerce_payment_filter_used';

	public const LIST_ACTIVE  = 'active';
	public const LIST_HISTORY = 'history';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Payment Method Filter', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		// HPOS orders list (both hooks since WooCommerce 7.3).
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_hpos_dropdown' ), 20, 1 );
		add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', array( $this, 'filter_hpos_query' ) );

		// Legacy post-based orders list.
		add_action( 'restrict_manage_posts', array( $this, 'render_legacy_dropdown' ), 20, 1 );
		add_action( 'pre_get_posts', array( $this, 'filter_legacy_query' ) );
	}

	/** @param mixed $order_type */
	public function render_hpos_dropdown( $order_type ): void {
		if ( 'shop_order' === $order_type ) {
			$this->render_dropdown();
		}
	}

	/** @param mixed $post_type */
	public function render_legacy_dropdown( $post_type ): void {
		if ( 'shop_order' === $post_type ) {
			$this->render_dropdown();
		}
	}

	/**
	 * @param mixed $args
	 * @return mixed
	 */
	public function filter_hpos_query( $args ) {
		$method = self::requested();
		if ( '' === $method || ! is_array( $args ) ) {
			return $args;
		}

		$args['payment_method'] = $method;

		return $args;
	}

	/**
	 * The legacy list is a plain WP_Query on shop_order posts with no
	 * WooCommerce query hook of its own, so the filter goes in as a meta query
	 * on the screen's main query. Only reached when orders live in posts.
	 *
	 * @param mixed $query
	 */
	public function filter_legacy_query( $query ): void {
		if ( ! $query instanceof WP_Query || ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}

		$method = self::requested();
		if ( '' === $method ) {
			return;
		}

		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = array(
			'key'   => '_payment_method',
			'value' => $method,
		);
		$query->set( 'meta_query', $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the legacy orders list has no other way to filter by payment method.
	}

	/**
	 * The gateways switched on now, since some payment plugins register dozens
	 * of methods a store never switches on. The order-history setting adds
	 * every method an order was paid with: a gateway switched off or removed
	 * since can still hold thousands of orders. A removed gateway is titled as
	 * on its latest order.
	 *
	 * @return array<string, string> Gateway id => title.
	 */
	public static function options( string $current = '' ): array {
		$options  = array();
		$used     = self::LIST_HISTORY === self::listed() ? self::used_methods() : array();
		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();

		foreach ( $gateways as $id => $gateway ) {
			$id = (string) $id;
			if ( 'yes' !== $gateway->enabled && ! isset( $used[ $id ] ) ) {
				continue;
			}

			$title = trim( wp_strip_all_tags( (string) $gateway->get_title() ) );
			if ( '' === $title ) {
				$title = trim( wp_strip_all_tags( (string) $gateway->get_method_title() ) );
			}
			$options[ $id ] = '' !== $title ? $title : $id;
		}

		foreach ( $used as $id => $order_id ) {
			if ( isset( $options[ $id ] ) ) {
				continue;
			}

			$order          = wc_get_order( $order_id );
			$title          = $order instanceof WC_Order ? trim( wp_strip_all_tags( $order->get_payment_method_title() ) ) : '';
			$options[ $id ] = '' !== $title ? $title : $id;
		}

		if ( '' !== $current && ! isset( $options[ $current ] ) ) {
			$options[ $current ] = $current;
		}

		return $options;
	}

	/**
	 * Every payment method stored on a shop order, read with SQL because
	 * wc_get_orders() can't return distinct values. It scans every order, so
	 * it is cached for a day; a method first used within that day comes from
	 * a switched-on gateway, which is listed anyway.
	 *
	 * @return array<string, int> Payment method id => latest order ID.
	 */
	private static function used_methods(): array {
		$cached = get_transient( self::USED_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached in a transient; no WooCommerce API lists distinct payment methods.
			$rows = $wpdb->get_results( "SELECT payment_method AS method, MAX(id) AS latest FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND payment_method <> '' GROUP BY payment_method", ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached in a transient; no WooCommerce API lists distinct payment methods.
			$rows = $wpdb->get_results( "SELECT pm.meta_value AS method, MAX(pm.post_id) AS latest FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_payment_method' AND pm.meta_value <> '' AND p.post_type = 'shop_order' GROUP BY pm.meta_value", ARRAY_A );
		}

		$used = array();
		foreach ( (array) $rows as $row ) {
			$used[ (string) $row['method'] ] = (int) $row['latest'];
		}

		set_transient( self::USED_CACHE, $used, DAY_IN_SECONDS );

		return $used;
	}

	private static function listed(): string {
		return self::LIST_HISTORY === ( Settings::get( self::ID )['listed'] ?? '' ) ? self::LIST_HISTORY : self::LIST_ACTIVE;
	}

	/**
	 * A GET list filter, like WooCommerce's own customer and date filters: it
	 * only narrows what the current user can already list, so no nonce.
	 */
	public static function requested(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter, see above.
		return isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
	}

	private function render_dropdown(): void {
		$current = self::requested();

		echo '<label class="screen-reader-text" for="filter-by-rc-payment-method">' . esc_html__( 'Filter by payment method', 'romcommerce' ) . '</label>';
		echo '<select name="' . esc_attr( self::QUERY_VAR ) . '" id="filter-by-rc-payment-method">';
		echo '<option value="">' . esc_html__( 'All payment methods', 'romcommerce' ) . '</option>';
		foreach ( self::options( $current ) as $id => $title ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $current, $id, false ) . '>' . esc_html( $title ) . '</option>';
		}
		echo '</select>';
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			$listed = sanitize_key( wp_unslash( $_POST['rc_listed'] ?? '' ) );
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			Settings::update( self::ID, array( 'listed' => self::LIST_HISTORY === $listed ? self::LIST_HISTORY : self::LIST_ACTIVE ) );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Adds a payment method dropdown to the WooCommerce orders list, next to the date and customer filters, so you can see only the orders paid by card, cash on delivery, bank transfer and so on.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Module', 'romcommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Enable', 'romcommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Payment methods listed', 'romcommerce' ) . '</th><td><fieldset>';
		echo '<label><input type="radio" name="rc_listed" value="' . esc_attr( self::LIST_ACTIVE ) . '"' . checked( self::listed(), self::LIST_ACTIVE, false ) . '> ';
		echo esc_html__( 'Switched on now', 'romcommerce' ) . '</label><br>';
		echo '<label><input type="radio" name="rc_listed" value="' . esc_attr( self::LIST_HISTORY ) . '"' . checked( self::listed(), self::LIST_HISTORY, false ) . '> ';
		echo esc_html__( 'Switched on now, plus every method used on past orders', 'romcommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'The second also lists methods switched off or removed since, so their orders can still be filtered. It reads all your orders once a day to build the list, which takes longer on large stores.', 'romcommerce' ) . '</p>';
		echo '</fieldset></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';
	}
}
